<?php

declare(strict_types=1);

use App\Services\MembershipApplicationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('icgu:health', function (): int {
    $this->info('ICGU application booted successfully.');
    return 0;
})->purpose('Verify that the ICGU Laravel application can boot.');

Artisan::command('icgu:verify-sprint2', function (): int {
    DB::beginTransaction();

    try {
        $service = app(MembershipApplicationService::class);
        $created = $service->create([
            'plan_code' => 'individual',
            'email' => 'sprint2.applicant@prototype.invalid',
            'phone' => '+256700009999',
            'first_name' => 'Sprint',
            'last_name' => 'Applicant',
            'job_title' => 'Director',
        ]);

        $application = $created['application'];
        if (! $application->tokenMatches($created['token'])) {
            throw new \RuntimeException('Application token verification failed.');
        }
        if ($application->tokenMatches('incorrect-token')) {
            throw new \RuntimeException('Invalid application token was accepted.');
        }

        foreach ([['cv', 1], ['passport_photo', 1], ['passport_photo', 2]] as [$type, $sequence]) {
            $application->documents()->create([
                'document_type' => $type,
                'storage_disk' => 'local',
                'object_path' => "ci/{$application->reference}/{$type}-{$sequence}",
                'original_name' => "{$type}-{$sequence}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 128,
                'checksum_sha256' => hash('sha256', "{$type}-{$sequence}"),
            ]);
        }

        if ($service->missingRequirements($application->refresh()) !== []) {
            throw new \RuntimeException('Individual document requirements were not satisfied.');
        }

        $corporate = $service->create([
            'plan_code' => 'corporate',
            'email' => 'secretary@sprint2.prototype.invalid',
            'organisation' => [
                'legal_name' => 'Sprint Two Prototype Limited',
                'registration_number' => 'CI-SPRINT2-001',
                'entity_type' => 'company',
                'country' => 'Uganda',
            ],
            'representatives' => [[
                'first_name' => 'Primary',
                'last_name' => 'Representative',
                'email' => 'rep@sprint2.prototype.invalid',
                'position' => 'Director',
                'is_primary' => true,
            ]],
        ])['application'];

        if ($corporate->organisation === null || $corporate->representatives()->count() !== 1) {
            throw new \RuntimeException('Corporate application relationships failed.');
        }

        $this->info('Sprint 2 membership application domain verified successfully.');
        return 0;
    } finally {
        DB::rollBack();
    }
})->purpose('Exercise Sprint 2 application tokens, requirements, organisations and representatives.');
