<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\PilotImportBatch;
use App\Models\PilotImportRow;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SplFileObject;
use Throwable;

final class PilotMemberImportService
{
    /** @var list<string> */
    public const HEADER = [
        'registration_number',
        'type',
        'plan_code',
        'status',
        'first_name',
        'last_name',
        'company_name',
        'email',
        'phone',
        'organization',
        'job_title',
        'membership_tier',
        'is_job_seeker',
        'registration_date',
        'period_start',
        'period_end',
        'target_year',
    ];

    public function __construct(
        private readonly RegistrationNumberService $registrationNumbers,
    ) {}

    public function import(string $path, bool $commit = false, ?User $approver = null, ?string $sourceName = null): PilotImportBatch
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['source' => 'Pilot import source is not a readable file.']);
        }

        if ($commit && ($approver === null || ! $approver->is_active || ! $approver->hasStaffRole())) {
            throw ValidationException::withMessages(['approved_by' => 'Commit mode requires an active Secretariat approver.']);
        }
        if ($commit && (bool) config('production.require_staff_mfa', false) && $approver?->mfa_confirmed_at === null) {
            throw ValidationException::withMessages(['approved_by' => 'Commit mode requires an MFA-confirmed Secretariat approver while staff MFA is enabled.']);
        }

        $sourceHash = hash_file('sha256', $path);
        if (! is_string($sourceHash)) {
            throw new RuntimeException('Unable to hash pilot import source.');
        }

        if ($commit && PilotImportBatch::query()->where('source_sha256', $sourceHash)->where('status', 'committed')->exists()) {
            throw ValidationException::withMessages(['source' => 'This exact pilot source file has already been committed.']);
        }

        [$rows, $detectedSourceName] = $this->readRows($path);
        $sourceName = trim((string) $sourceName) !== '' ? trim((string) $sourceName) : $detectedSourceName;
        $maxRows = max(1, (int) config('production.pilot_import_max_rows', 5000));
        if (count($rows) > $maxRows) {
            throw ValidationException::withMessages([
                'source' => "Controlled imports are capped at {$maxRows} rows per batch.",
            ]);
        }

        $plans = MembershipPlan::query()->where('is_active', true)->get()->keyBy('code');
        $statuses = LookupStatus::query()->where('type', 'membership')->where('is_active', true)->get()->keyBy('code');
        $seenRegistrations = [];
        $seenEmails = [];

        $validated = [];
        $validCount = $conflictCount = $errorCount = 0;

        foreach ($rows as $rowNumber => $raw) {
            [$disposition, $issues, $normalized] = $this->validateRow(
                $raw,
                $rowNumber,
                $plans,
                $statuses,
                $seenRegistrations,
                $seenEmails,
            );

            match ($disposition) {
                'valid' => $validCount++,
                'conflict' => $conflictCount++,
                default => $errorCount++,
            };

            $validated[] = compact('rowNumber', 'raw', 'disposition', 'issues', 'normalized');
        }

        $batch = PilotImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'source_name' => $sourceName,
            'source_sha256' => $sourceHash,
            'status' => 'validated',
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'conflict_rows' => $conflictCount,
            'error_rows' => $errorCount,
            'summary' => [
                'mode' => $commit ? 'commit_requested' : 'dry_run',
                'max_rows' => $maxRows,
                'header_version' => 2,
            ],
            'completed_at' => now(),
        ]);

        foreach ($validated as $entry) {
            PilotImportRow::query()->create([
                'pilot_import_batch_id' => $batch->id,
                'row_number' => $entry['rowNumber'],
                'row_sha256' => hash('sha256', json_encode($entry['raw'], JSON_THROW_ON_ERROR)),
                'registration_number' => $entry['normalized']['registration_number'] ?? null,
                'disposition' => $entry['disposition'],
                'normalized_payload' => $entry['normalized'],
                'issues' => $entry['issues'] ?: null,
            ]);
        }

        if (! $commit) {
            return $batch->fresh('rows');
        }

        if ($conflictCount > 0 || $errorCount > 0) {
            return $batch->fresh('rows');
        }

        try {
            DB::transaction(function () use ($batch, $approver): void {
                foreach ($batch->rows()->where('disposition', 'valid')->orderBy('row_number')->lockForUpdate()->get() as $importRow) {
                    $payload = (array) $importRow->normalized_payload;
                    $status = LookupStatus::query()->where('type', 'membership')->where('code', $payload['status'])->firstOrFail();
                    $plan = MembershipPlan::query()->where('code', $payload['plan_code'])->firstOrFail();

                    $member = new Member();
                    $member->forceFill([
                        'registration_number' => $payload['registration_number'],
                        'type' => $payload['type'],
                        'title' => null,
                        'first_name' => $payload['first_name'],
                        'last_name' => $payload['last_name'],
                        'company_name' => $payload['company_name'],
                        'phone' => $payload['phone'],
                        'organization' => $payload['organization'],
                        'job_title' => $payload['job_title'],
                        'membership_tier' => $payload['membership_tier'],
                        'is_job_seeker' => $payload['is_job_seeker'],
                        'registration_date' => $payload['registration_date'],
                        'status_id' => $status->id,
                        'membership_plan_id' => $plan->id,
                        'is_archived' => false,
                    ])->save();

                    $member->emails()->create([
                        'email' => $payload['email'],
                        'email_type' => 'work',
                        'is_primary' => true,
                        'is_active' => true,
                    ]);

                    if ($payload['period_start'] !== null) {
                        $member->periods()->create([
                            'start_date' => $payload['period_start'],
                            'end_date' => $payload['period_end'],
                            'target_year' => $payload['target_year'],
                            'is_backdated' => (int) $payload['target_year'] < (int) now()->year,
                            'is_future' => $payload['period_start'] > today()->toDateString(),
                            'notes' => 'Controlled member import '.$batch->uuid,
                            'created_by' => $approver?->id,
                        ]);
                    }

                    $member->statusHistory()->create([
                        'from_status_id' => null,
                        'to_status_id' => $status->id,
                        'reason_code' => 'pilot_import',
                        'reason_notes' => 'Controlled member import '.$batch->uuid,
                        'effective_at' => now(),
                        'actor_id' => $approver?->id,
                    ]);

                    $this->advanceRegistrationSequence($payload['registration_number']);

                    $importRow->forceFill([
                        'disposition' => 'imported',
                        'member_id' => $member->id,
                    ])->save();
                }

                $batch->forceFill([
                    'status' => 'committed',
                    'approved_by' => $approver?->id,
                    'imported_rows' => $batch->valid_rows,
                    'committed_at' => now(),
                    'completed_at' => now(),
                    'summary' => array_merge((array) $batch->summary, [
                        'mode' => 'committed',
                        'member_ids' => $batch->rows()->whereNotNull('member_id')->pluck('member_id')->all(),
                    ]),
                ])->save();

                AuditLog::query()->create([
                    'user_id' => $approver?->id,
                    'action' => 'pilot_import_committed',
                    'entity' => PilotImportBatch::class,
                    'entity_id' => $batch->id,
                    'after_payload' => [
                        'batch_uuid' => $batch->uuid,
                        'source_sha256' => $batch->source_sha256,
                        'imported_rows' => $batch->imported_rows,
                    ],
                    'request_id' => (string) Str::uuid(),
                ]);
            });
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'completed_at' => now(),
                'summary' => array_merge((array) $batch->summary, [
                    'mode' => 'failed',
                    'failure' => Str::limit($exception->getMessage(), 500, ''),
                ]),
            ])->save();

            throw $exception;
        }

        return $batch->fresh('rows');
    }

    /** @return array{0: array<int,array<string,string>>, 1: string} */
    private function readRows(string $path): array
    {
        $file = new SplFileObject($path, 'rb');
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        $header = $file->fgetcsv();
        if (! is_array($header)) {
            throw ValidationException::withMessages(['source' => 'Member CSV has no header row.']);
        }

        $normalizedHeader = array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $header,
        );
        $normalizedHeader[0] = preg_replace('/^\xEF\xBB\xBF/', '', $normalizedHeader[0]) ?? $normalizedHeader[0];

        if ($normalizedHeader !== self::HEADER) {
            throw ValidationException::withMessages([
                'source' => 'CSV header must exactly match the ICGU member import template shown in the admin portal.',
            ]);
        }

        $rows = [];
        $rowNumber = 1;
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            $rowNumber++;
            if (! is_array($values) || $values === [null]) {
                continue;
            }

            $values = array_map(static fn ($value): string => trim((string) $value), $values);
            if (count(array_filter($values, static fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }
            if (count($values) !== count(self::HEADER)) {
                throw ValidationException::withMessages([
                    'source' => "CSV row {$rowNumber} has ".count($values).' columns; '.count(self::HEADER).' are required.',
                ]);
            }

            $rows[$rowNumber] = array_combine(self::HEADER, $values);
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['source' => 'Member CSV contains no data rows.']);
        }

        return [$rows, basename($path)];
    }

    /**
     * @param \Illuminate\Support\Collection<string,MembershipPlan> $plans
     * @param \Illuminate\Support\Collection<string,LookupStatus> $statuses
     * @param array<string,int> $seenRegistrations
     * @param array<string,int> $seenEmails
     * @return array{0:string,1:list<string>,2:array<string,mixed>}
     */
    private function validateRow(
        array $raw,
        int $rowNumber,
        $plans,
        $statuses,
        array &$seenRegistrations,
        array &$seenEmails,
    ): array {
        $issues = [];
        $conflicts = [];

        $registration = strtoupper(trim($raw['registration_number']));
        $type = strtolower(trim($raw['type']));
        $planCode = strtolower(trim($raw['plan_code']));
        $statusCode = strtoupper(trim($raw['status']));
        $email = strtolower(trim($raw['email']));
        $jobSeeker = $this->booleanValue($raw['is_job_seeker']);

        $parsedRegistration = $this->registrationNumbers->parse($registration);
        if ($parsedRegistration === null) {
            $issues[] = 'registration_number must use ICGU/NNN/YYYY.';
        }

        if (! in_array($type, ['individual', 'corporate'], true)) {
            $issues[] = 'type must be individual or corporate.';
        }

        $plan = $plans->get($planCode);
        if (! $plan instanceof MembershipPlan) {
            $issues[] = 'plan_code is not an active membership plan.';
        } elseif ($type === 'corporate' && $plan->audience !== 'corporate') {
            $issues[] = 'corporate rows require a corporate membership plan.';
        } elseif ($type === 'individual' && $plan->audience === 'corporate') {
            $issues[] = 'individual rows cannot use a corporate membership plan.';
        }

        if (! $statuses->has($statusCode)) {
            $issues[] = 'status is not an active membership status.';
        }

        if ($type === 'individual' && (trim($raw['first_name']) === '' || trim($raw['last_name']) === '')) {
            $issues[] = 'individual rows require first_name and last_name.';
        }
        if ($type === 'corporate' && trim($raw['company_name']) === '') {
            $issues[] = 'corporate rows require company_name.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $issues[] = 'email is invalid.';
        }
        if ($jobSeeker === null) {
            $issues[] = 'is_job_seeker must be yes/no, true/false, or 1/0.';
        }

        $registrationDate = $this->dateValue($raw['registration_date']);
        if ($registrationDate === null) {
            $issues[] = 'registration_date must be YYYY-MM-DD.';
        }

        $periodValues = [$raw['period_start'], $raw['period_end'], $raw['target_year']];
        $periodProvided = count(array_filter($periodValues, static fn ($value): bool => trim((string) $value) !== '')) > 0;
        $periodStart = $periodEnd = null;
        $targetYear = null;

        if ($periodProvided) {
            if (count(array_filter($periodValues, static fn ($value): bool => trim((string) $value) !== '')) !== 3) {
                $issues[] = 'period_start, period_end and target_year must be supplied together.';
            } else {
                $periodStart = $this->dateValue($raw['period_start']);
                $periodEnd = $this->dateValue($raw['period_end']);
                $targetYear = filter_var($raw['target_year'], FILTER_VALIDATE_INT);

                if ($periodStart === null || $periodEnd === null) {
                    $issues[] = 'period dates must be YYYY-MM-DD.';
                } elseif ($periodEnd <= $periodStart) {
                    $issues[] = 'period_end must be after period_start.';
                }

                $currentYear = (int) now()->year;
                if ($targetYear === false || $targetYear < 1990 || $targetYear > $currentYear + 2) {
                    $issues[] = 'target_year is outside the accepted import range.';
                }
            }
        } elseif ($statusCode === 'ACTIVE') {
            $issues[] = 'ACTIVE members require a membership period.';
        }

        if (isset($seenRegistrations[$registration])) {
            $conflicts[] = 'registration_number duplicates CSV row '.$seenRegistrations[$registration].'.';
        } else {
            $seenRegistrations[$registration] = $rowNumber;
        }

        if ($email !== '') {
            if (isset($seenEmails[$email])) {
                $conflicts[] = 'email duplicates CSV row '.$seenEmails[$email].'.';
            } else {
                $seenEmails[$email] = $rowNumber;
            }
        }

        if ($registration !== '' && Member::query()->where('registration_number', $registration)->exists()) {
            $conflicts[] = 'registration_number already exists in the membership database.';
        }
        if ($email !== '' && DB::table('member_emails')->whereRaw('lower(email) = ?', [$email])->exists()) {
            $conflicts[] = 'email already belongs to an existing member.';
        }

        $normalized = [
            'registration_number' => $registration,
            'type' => $type,
            'plan_code' => $planCode,
            'status' => $statusCode,
            'first_name' => trim($raw['first_name']) ?: null,
            'last_name' => trim($raw['last_name']) ?: null,
            'company_name' => trim($raw['company_name']) ?: null,
            'email' => $email,
            'phone' => trim($raw['phone']) ?: null,
            'organization' => trim($raw['organization']) ?: null,
            'job_title' => trim($raw['job_title']) ?: null,
            'membership_tier' => trim($raw['membership_tier']) ?: null,
            'is_job_seeker' => $jobSeeker ?? false,
            'registration_date' => $registrationDate,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'target_year' => $targetYear === false ? null : $targetYear,
        ];

        if ($issues !== []) {
            return ['error', array_values(array_unique(array_merge($issues, $conflicts))), $normalized];
        }
        if ($conflicts !== []) {
            return ['conflict', array_values(array_unique($conflicts)), $normalized];
        }

        return ['valid', [], $normalized];
    }

    private function dateValue(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    private function booleanValue(string $value): ?bool
    {
        $value = strtolower(trim($value));
        if (in_array($value, ['1', 'true', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'n', ''], true)) {
            return false;
        }

        return null;
    }

    private function advanceRegistrationSequence(string $registrationNumber): void
    {
        $parsed = $this->registrationNumbers->parse($registrationNumber);
        if ($parsed === null) {
            throw new RuntimeException('Cannot advance registration sequence for an invalid registration number.');
        }

        DB::statement(
            <<<'SQL'
            INSERT INTO registration_sequences (year, last_sequence, created_at, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (year)
            DO UPDATE SET
                last_sequence = GREATEST(registration_sequences.last_sequence, EXCLUDED.last_sequence),
                updated_at = EXCLUDED.updated_at
            SQL,
            [$parsed['year'], $parsed['sequence'], now(), now()],
        );
    }
}
