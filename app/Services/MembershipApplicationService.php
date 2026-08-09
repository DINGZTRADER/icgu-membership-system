<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\ApplicationRepresentative;
use App\Models\MembershipApplication;
use App\Models\MembershipPlan;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MembershipApplicationService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{application: MembershipApplication, token: string} */
    public function create(array $data): array
    {
        $plan = MembershipPlan::query()->where('code', $data['plan_code'])->where('is_active', true)->firstOrFail();
        $token = Str::random(64);

        $application = DB::transaction(function () use ($data, $plan, $token): MembershipApplication {
            $organisation = null;

            if ($plan->audience === 'corporate') {
                $organisation = Organisation::query()->create($data['organisation']);
            }

            $application = MembershipApplication::query()->create([
                'reference' => $this->nextReference(),
                'access_token_hash' => hash('sha256', $token),
                'membership_plan_id' => $plan->id,
                'organisation_id' => $organisation?->id,
                'title' => $data['title'] ?? null,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'institution_name' => $data['institution_name'] ?? null,
                'applicant_notes' => $data['applicant_notes'] ?? null,
            ]);

            foreach ($data['representatives'] ?? [] as $index => $representative) {
                $application->representatives()->create([
                    ...$representative,
                    'email' => mb_strtolower($representative['email']),
                    'is_primary' => (bool) ($representative['is_primary'] ?? $index === 0),
                ]);
            }

            return $application;
        });

        return ['application' => $application->load(['plan', 'organisation', 'representatives']), 'token' => $token];
    }

    public function authorizeToken(MembershipApplication $application, string $token): void
    {
        if (! $application->tokenMatches($token)) {
            throw new AuthorizationException('Invalid application access token.');
        }
    }

    public function storeDocument(
        MembershipApplication $application,
        UploadedFile $file,
        string $documentType,
        ?int $representativeId = null,
        ?User $uploader = null,
    ): ApplicationDocument {
        if (! in_array($application->status, ['draft', 'submitted'], true)) {
            throw ValidationException::withMessages(['application' => 'Documents cannot be changed at this stage.']);
        }

        if ($representativeId !== null) {
            $belongs = $application->representatives()->whereKey($representativeId)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages(['representative_id' => 'Representative does not belong to this application.']);
            }
        }

        $disk = (string) config('filesystems.membership_documents', 'local');
        $extension = strtolower($file->guessExtension() ?: 'bin');
        $filename = Str::uuid().'.'.$extension;
        $path = 'membership-applications/'.$application->reference.'/'.$filename;
        $checksum = hash_file('sha256', $file->getRealPath());

        if (! Storage::disk($disk)->putFileAs('membership-applications/'.$application->reference, $file, $filename)) {
            throw new RuntimeException('Unable to store membership document.');
        }

        try {
            return $application->documents()->create([
                'application_representative_id' => $representativeId,
                'document_type' => $documentType,
                'storage_disk' => $disk,
                'object_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'checksum_sha256' => $checksum,
                'uploaded_by' => $uploader?->id,
            ]);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    /** @return list<string> */
    public function missingRequirements(MembershipApplication $application): array
    {
        $application->loadMissing(['plan', 'documents', 'representatives']);
        $requirements = $application->plan->requirements ?? [];
        $missing = [];

        foreach (($requirements['application_documents'] ?? []) as $type => $minimum) {
            $count = $application->documents
                ->whereNull('application_representative_id')
                ->where('document_type', $type)
                ->count();
            if ($count < (int) $minimum) {
                $missing[] = sprintf('%s (%d required, %d uploaded)', $type, $minimum, $count);
            }
        }

        $representativeRequirements = $requirements['primary_representative_documents'] ?? [];
        if ($representativeRequirements !== []) {
            $primary = $application->representatives->firstWhere('is_primary', true);
            if ($primary === null) {
                $missing[] = 'primary representative';
            } else {
                foreach ($representativeRequirements as $type => $minimum) {
                    $count = $application->documents
                        ->where('application_representative_id', $primary->id)
                        ->where('document_type', $type)
                        ->count();
                    if ($count < (int) $minimum) {
                        $missing[] = sprintf('primary representative %s (%d required, %d uploaded)', $type, $minimum, $count);
                    }
                }
            }
        }

        return $missing;
    }

    public function submit(MembershipApplication $application, bool $integrityAccepted, bool $termsAccepted): MembershipApplication
    {
        if ($application->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only draft applications may be submitted.']);
        }
        if (! $integrityAccepted || ! $termsAccepted) {
            throw ValidationException::withMessages(['declaration' => 'Integrity declaration and terms must both be accepted.']);
        }

        $missing = $this->missingRequirements($application);
        if ($missing !== []) {
            throw ValidationException::withMessages(['documents' => 'Missing requirements: '.implode(', ', $missing)]);
        }

        $before = $application->only(['status', 'submitted_at']);
        $application->forceFill([
            'status' => 'submitted',
            'integrity_declaration_at' => now(),
            'terms_accepted_at' => now(),
            'submitted_at' => now(),
            'version' => $application->version + 1,
        ])->save();
        $this->audit->record('application_submitted', $application, before: $before, after: $application->only(['status', 'submitted_at']));

        return $application->refresh();
    }

    public function startReview(MembershipApplication $application, User $actor): MembershipApplication
    {
        $this->assertStatus($application, ['submitted']);
        return $this->transition($application, $actor, 'under_review', ['review_started_at' => now()], 'application_review_started');
    }

    public function approvePendingPayment(MembershipApplication $application, User $actor, ?string $notes = null): MembershipApplication
    {
        $this->assertStatus($application, ['submitted', 'under_review']);
        return $this->transition($application, $actor, 'approved_pending_payment', [
            'decided_at' => now(), 'decision_by' => $actor->id, 'decision_notes' => $notes,
        ], 'application_approved');
    }

    public function reject(MembershipApplication $application, User $actor, string $reason): MembershipApplication
    {
        $this->assertStatus($application, ['submitted', 'under_review']);
        return $this->transition($application, $actor, 'rejected', [
            'decided_at' => now(), 'decision_by' => $actor->id, 'decision_notes' => $reason,
        ], 'application_rejected');
    }

    private function transition(MembershipApplication $application, User $actor, string $status, array $extra, string $auditAction): MembershipApplication
    {
        $before = $application->only(['status', 'review_started_at', 'decided_at', 'decision_by', 'decision_notes']);
        $application->forceFill([...$extra, 'status' => $status, 'version' => $application->version + 1])->save();
        $this->audit->record($auditAction, $application, before: $before, after: $application->only(['status', 'review_started_at', 'decided_at', 'decision_by', 'decision_notes']));
        return $application->refresh();
    }

    private function assertStatus(MembershipApplication $application, array $allowed): void
    {
        if (! in_array($application->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Application is not in a valid state for this action.']);
        }
    }

    private function nextReference(): string
    {
        do {
            $reference = 'ICGU-APP-'.now()->format('Y').'-'.Str::upper(Str::random(8));
        } while (MembershipApplication::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
