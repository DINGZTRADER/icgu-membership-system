<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Services\PilotMemberImportService;
use App\Services\RegistrationNumberService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class StaffMemberManagementController extends Controller
{
    public function __construct(
        private readonly RegistrationNumberService $registrationNumbers,
        private readonly PilotMemberImportService $imports,
    ) {}

    public function create(): View
    {
        return view('staff.members.create', $this->formData());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'registration_number' => ['nullable', 'string', 'max:20'],
            'type' => ['required', Rule::in(['individual', 'corporate'])],
            'title' => ['nullable', 'string', 'max:20'],
            'first_name' => ['nullable', 'required_if:type,individual', 'string', 'max:100'],
            'last_name' => ['nullable', 'required_if:type,individual', 'string', 'max:100'],
            'company_name' => ['nullable', 'required_if:type,corporate', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
            'organization' => ['nullable', 'string', 'max:200'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'membership_plan_id' => [
                'required',
                Rule::exists('membership_plans', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'membership_tier' => ['nullable', 'string', 'max:80'],
            'status_id' => [
                'required',
                Rule::exists('lookup_statuses', 'id')->where(fn ($query) => $query->where('type', 'membership')->where('is_active', true)),
            ],
            'registration_date' => ['required', 'date_format:Y-m-d'],
            'period_start' => ['nullable', 'date_format:Y-m-d'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after:period_start'],
            'target_year' => ['nullable', 'integer', 'min:1990', 'max:'.((int) now()->year + 2)],
            'is_job_seeker' => ['nullable', 'boolean'],
            'profile_photo' => [
                Rule::requiredIf(fn (): bool => $request->string('type')->toString() === 'individual'),
                'file', 'mimes:jpg,jpeg,png,webp', 'max:5120',
            ],
            'cv' => [
                Rule::requiredIf(fn (): bool => $request->boolean('is_job_seeker')),
                'file', 'mimes:pdf,doc,docx', 'max:10240',
            ],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));
        if (DB::table('member_emails')->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to an existing member.']);
        }

        $plan = MembershipPlan::query()->findOrFail((int) $validated['membership_plan_id']);
        $this->assertPlanMatchesType($plan, (string) $validated['type']);

        $status = LookupStatus::query()->findOrFail((int) $validated['status_id']);
        $this->assertPeriodCompleteness($status->code, $validated);

        $registrationNumber = strtoupper(trim((string) ($validated['registration_number'] ?? '')));
        if ($registrationNumber !== '') {
            if ($this->registrationNumbers->parse($registrationNumber) === null) {
                throw ValidationException::withMessages(['registration_number' => 'Registration number must use ICGU/NNN/YYYY.']);
            }
            if (Member::query()->where('registration_number', $registrationNumber)->exists()) {
                throw ValidationException::withMessages(['registration_number' => 'That registration number already exists.']);
            }
        }

        $disk = (string) config('filesystems.membership_documents', 'local');
        $profilePhotoPath = null;
        $cvPath = null;

        try {
            $member = DB::transaction(function () use (
                $request,
                $validated,
                $email,
                $status,
                $registrationNumber,
                $disk,
                &$profilePhotoPath,
                &$cvPath,
            ): Member {
                $number = $registrationNumber !== ''
                    ? $registrationNumber
                    : $this->registrationNumbers->generate((int) substr((string) $validated['registration_date'], 0, 4));

                $member = new Member();
                $member->forceFill([
                    'registration_number' => $number,
                    'type' => $validated['type'],
                    'title' => $validated['title'] ?? null,
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name' => $validated['last_name'] ?? null,
                    'company_name' => $validated['company_name'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'organization' => $validated['organization'] ?? null,
                    'job_title' => $validated['job_title'] ?? null,
                    'membership_plan_id' => (int) $validated['membership_plan_id'],
                    'membership_tier' => trim((string) ($validated['membership_tier'] ?? '')) ?: null,
                    'is_job_seeker' => $request->boolean('is_job_seeker'),
                    'registration_date' => $validated['registration_date'],
                    'status_id' => (int) $validated['status_id'],
                    'is_archived' => false,
                ])->save();

                if ($request->hasFile('profile_photo')) {
                    $profilePhotoPath = $this->storeMemberFile($request, 'profile_photo', $member, 'profile', $disk);
                }
                if ($request->hasFile('cv')) {
                    $cvPath = $this->storeMemberFile($request, 'cv', $member, 'cv', $disk);
                }

                $member->forceFill([
                    'profile_photo_path' => $profilePhotoPath,
                    'cv_path' => $cvPath,
                ])->save();

                $member->emails()->create([
                    'email' => $email,
                    'email_type' => 'work',
                    'is_primary' => true,
                    'is_active' => true,
                ]);

                if (! empty($validated['period_start'])) {
                    $member->periods()->create([
                        'start_date' => $validated['period_start'],
                        'end_date' => $validated['period_end'],
                        'target_year' => (int) $validated['target_year'],
                        'is_backdated' => (int) $validated['target_year'] < (int) now()->year,
                        'is_future' => $validated['period_start'] > today()->toDateString(),
                        'notes' => 'Created manually by Secretariat.',
                        'created_by' => $request->user()?->id,
                    ]);
                }

                $member->statusHistory()->create([
                    'from_status_id' => null,
                    'to_status_id' => $status->id,
                    'reason_code' => 'manual_registration',
                    'reason_notes' => 'Member created by Secretariat.',
                    'effective_at' => now(),
                    'actor_id' => $request->user()?->id,
                ]);

                if ($registrationNumber !== '') {
                    $this->advanceRegistrationSequence($registrationNumber);
                }

                AuditLog::query()->create([
                    'user_id' => $request->user()?->id,
                    'action' => 'member_created',
                    'entity' => Member::class,
                    'entity_id' => $member->id,
                    'after_payload' => [
                        'registration_number' => $member->registration_number,
                        'membership_tier' => $member->membership_tier,
                        'is_job_seeker' => $member->is_job_seeker,
                    ],
                    'request_id' => (string) Str::uuid(),
                ]);

                return $member;
            });
        } catch (Throwable $exception) {
            if ($profilePhotoPath !== null) {
                Storage::disk($disk)->delete($profilePhotoPath);
            }
            if ($cvPath !== null) {
                Storage::disk($disk)->delete($cvPath);
            }
            throw $exception;
        }

        return redirect()->route('staff.members.show', $member)
            ->with('success', 'Member added to the ICGU register successfully.');
    }

    public function importForm(): View
    {
        return view('staff.members.import', array_merge($this->formData(), [
            'headers' => PilotMemberImportService::HEADER,
            'batch' => null,
        ]));
    }

    public function import(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'member_csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'action' => ['required', Rule::in(['validate', 'commit'])],
        ]);

        $file = $request->file('member_csv');
        $path = $file?->getRealPath();
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages(['member_csv' => 'The uploaded CSV could not be read.']);
        }

        $commit = $validated['action'] === 'commit';
        $batch = $this->imports->import(
            $path,
            $commit,
            $request->user(),
            $file->getClientOriginalName(),
        );

        if ($commit && $batch->status === 'committed') {
            return redirect()->route('staff.members.index')
                ->with('success', number_format((int) $batch->imported_rows).' members imported successfully.');
        }

        return view('staff.members.import', array_merge($this->formData(), [
            'headers' => PilotMemberImportService::HEADER,
            'batch' => $batch,
        ]));
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'wb');
            if ($handle === false) {
                return;
            }

            fputcsv($handle, PilotMemberImportService::HEADER);
            fputcsv($handle, [
                'ICGU/001/2026', 'individual', 'individual', 'ACTIVE', 'Jane', 'Doe', '',
                'jane.doe@example.org', '+256700000000', 'Example Organisation', 'Governance Officer',
                'Professional', 'yes', '2026-01-15', '2026-01-15', '2027-01-14', '2026',
            ]);
            fclose($handle);
        }, 'icgu-member-import-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updateCareerAssets(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'membership_tier' => ['nullable', 'string', 'max:80'],
            'is_job_seeker' => ['nullable', 'boolean'],
            'profile_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'cv' => [
                Rule::requiredIf(fn (): bool => $request->boolean('is_job_seeker') && $member->cv_path === null),
                'nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240',
            ],
        ]);

        $disk = (string) config('filesystems.membership_documents', 'local');
        $oldPhoto = $member->profile_photo_path;
        $oldCv = $member->cv_path;
        $newPhoto = null;
        $newCv = null;

        try {
            if ($request->hasFile('profile_photo')) {
                $newPhoto = $this->storeMemberFile($request, 'profile_photo', $member, 'profile', $disk);
            }
            if ($request->hasFile('cv')) {
                $newCv = $this->storeMemberFile($request, 'cv', $member, 'cv', $disk);
            }

            $before = [
                'membership_tier' => $member->membership_tier,
                'is_job_seeker' => $member->is_job_seeker,
                'profile_photo_path' => $member->profile_photo_path,
                'cv_path' => $member->cv_path,
            ];

            $member->forceFill([
                'membership_tier' => trim((string) ($validated['membership_tier'] ?? '')) ?: null,
                'is_job_seeker' => $request->boolean('is_job_seeker'),
                'profile_photo_path' => $newPhoto ?? $member->profile_photo_path,
                'cv_path' => $newCv ?? $member->cv_path,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $request->user()?->id,
                'action' => 'member_profile_assets_updated',
                'entity' => Member::class,
                'entity_id' => $member->id,
                'before_payload' => $before,
                'after_payload' => [
                    'membership_tier' => $member->membership_tier,
                    'is_job_seeker' => $member->is_job_seeker,
                    'profile_photo_path' => $member->profile_photo_path,
                    'cv_path' => $member->cv_path,
                ],
                'request_id' => (string) Str::uuid(),
            ]);
        } catch (Throwable $exception) {
            if ($newPhoto !== null) {
                Storage::disk($disk)->delete($newPhoto);
            }
            if ($newCv !== null) {
                Storage::disk($disk)->delete($newCv);
            }
            throw $exception;
        }

        if ($newPhoto !== null && $oldPhoto !== null) {
            Storage::disk($disk)->delete($oldPhoto);
        }
        if ($newCv !== null && $oldCv !== null) {
            Storage::disk($disk)->delete($oldCv);
        }

        return back()->with('success', 'Member profile, tier and career information updated.');
    }

    public function photo(Member $member): StreamedResponse
    {
        abort_if($member->profile_photo_path === null, 404);
        return $this->streamInline($member->profile_photo_path);
    }

    public function cv(Member $member): StreamedResponse
    {
        abort_if($member->cv_path === null, 404);
        $disk = (string) config('filesystems.membership_documents', 'local');
        abort_unless(Storage::disk($disk)->exists($member->cv_path), 404);

        return Storage::disk($disk)->download(
            $member->cv_path,
            Str::slug($member->display_name).'-cv.'.pathinfo($member->cv_path, PATHINFO_EXTENSION),
        );
    }

    /** @return array<string,mixed> */
    private function formData(): array
    {
        return [
            'plans' => MembershipPlan::query()->where('is_active', true)->orderBy('audience')->orderBy('name')->get(),
            'statuses' => LookupStatus::query()->where('type', 'membership')->where('is_active', true)->orderBy('sort_order')->orderBy('label')->get(),
        ];
    }

    private function assertPlanMatchesType(MembershipPlan $plan, string $type): void
    {
        if ($type === 'corporate' && $plan->audience !== 'corporate') {
            throw ValidationException::withMessages(['membership_plan_id' => 'Corporate members require a corporate membership plan.']);
        }
        if ($type === 'individual' && $plan->audience === 'corporate') {
            throw ValidationException::withMessages(['membership_plan_id' => 'Individual members cannot use a corporate membership plan.']);
        }
    }

    /** @param array<string,mixed> $validated */
    private function assertPeriodCompleteness(string $statusCode, array $validated): void
    {
        $values = [
            $validated['period_start'] ?? null,
            $validated['period_end'] ?? null,
            $validated['target_year'] ?? null,
        ];
        $provided = count(array_filter($values, fn ($value): bool => $value !== null && $value !== ''));

        if ($statusCode === 'ACTIVE' && $provided !== 3) {
            throw ValidationException::withMessages(['period_start' => 'Active members require period start, period end and target year.']);
        }
        if ($provided > 0 && $provided !== 3) {
            throw ValidationException::withMessages(['period_start' => 'Period start, period end and target year must be supplied together.']);
        }
    }

    private function storeMemberFile(Request $request, string $field, Member $member, string $folder, string $disk): string
    {
        $file = $request->file($field);
        if ($file === null) {
            throw new \RuntimeException("Missing uploaded file: {$field}.");
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = 'members/'.$member->id.'/'.$folder.'/'.Str::uuid().'.'.$extension;
        $stored = $file->storeAs(dirname($path), basename($path), $disk);
        if (! is_string($stored) || $stored === '') {
            throw new \RuntimeException("Unable to store uploaded {$field}.");
        }

        return $stored;
    }

    private function streamInline(string $path): StreamedResponse
    {
        $disk = (string) config('filesystems.membership_documents', 'local');
        abort_unless(Storage::disk($disk)->exists($path), 404);
        $mime = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';

        return response()->stream(function () use ($disk, $path): void {
            $stream = Storage::disk($disk)->readStream($path);
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function advanceRegistrationSequence(string $registrationNumber): void
    {
        $parsed = $this->registrationNumbers->parse($registrationNumber);
        if ($parsed === null) {
            return;
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
