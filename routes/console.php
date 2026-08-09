<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\MembershipApplicationService;
use App\Services\MembershipPaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

Artisan::command('icgu:verify-sprint3', function (): int {
    DB::beginTransaction();

    try {
        $applications = app(MembershipApplicationService::class);
        $payments = app(MembershipPaymentService::class);
        $actor = User::query()->create([
            'name' => 'Sprint Three Finance Officer',
            'email' => 'sprint3.finance@prototype.invalid',
            'password' => 'prototype-password',
            'is_active' => true,
        ]);

        $created = $applications->create([
            'plan_code' => 'individual',
            'email' => 'sprint3.applicant@prototype.invalid',
            'phone' => '+256700003003',
            'first_name' => 'Payment',
            'last_name' => 'Applicant',
            'job_title' => 'Director',
        ]);
        $application = $created['application'];

        foreach ([['cv', 1], ['passport_photo', 1], ['passport_photo', 2]] as [$type, $sequence]) {
            $application->documents()->create([
                'document_type' => $type,
                'storage_disk' => 'local',
                'object_path' => "ci/sprint3/{$application->reference}/{$type}-{$sequence}",
                'original_name' => "{$type}-{$sequence}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 128,
                'checksum_sha256' => hash('sha256', "sprint3-{$type}-{$sequence}"),
            ]);
        }

        $application = $applications->submit($application->refresh(), true, true);
        $application = $applications->startReview($application, $actor);
        $application = $applications->approvePendingPayment($application, $actor, 'Sprint 3 verification approval.');

        $invoice = $payments->createInvoice($application, $actor);
        if ((float) $invoice->amount !== 150000.0 || $invoice->invoice_number === null) {
            throw new \RuntimeException('Membership invoice amount or number is invalid.');
        }
        if ($payments->createInvoice($application, $actor)->id !== $invoice->id) {
            throw new \RuntimeException('Duplicate invoice was created for one application.');
        }

        $partial = $payments->recordPayment($application, $invoice, '50000', 'mobile_money', 'SPRINT3-PAY-001', $actor, 'prototype-provider');
        if ((float) $partial['balance_due'] !== 100000.0 || $partial['receipt']->receipt_number === null) {
            throw new \RuntimeException('Partial payment or receipt verification failed.');
        }

        $blockedAdmission = false;
        try {
            $payments->admit($application->refresh(), $actor);
        } catch (ValidationException) {
            $blockedAdmission = true;
        }
        if (! $blockedAdmission) {
            throw new \RuntimeException('Partially paid application was incorrectly admitted.');
        }

        $overpaymentRejected = false;
        try {
            $payments->recordPayment($application, $invoice, '100001', 'cash', 'SPRINT3-OVERPAY', $actor);
        } catch (ValidationException) {
            $overpaymentRejected = true;
        }
        if (! $overpaymentRejected) {
            throw new \RuntimeException('Overpayment was incorrectly accepted.');
        }

        $final = $payments->recordPayment($application, $invoice, '100000', 'bank_transfer', 'SPRINT3-PAY-002', $actor, 'prototype-bank');
        if ((float) $final['balance_due'] !== 0.0) {
            throw new \RuntimeException('Invoice did not settle after full payment.');
        }

        $member = $payments->admit($application->refresh(), $actor);
        if ($member->status?->code !== 'ACTIVE') {
            throw new \RuntimeException('Paid applicant was not activated.');
        }
        if (! preg_match('/^ICGU\/\d{3}\/\d{4}$/', $member->registration_number)) {
            throw new \RuntimeException('Registration number format is invalid.');
        }
        if ($member->periods()->count() !== 1 || $application->refresh()->status !== 'admitted') {
            throw new \RuntimeException('Admission did not create the initial membership period or application state.');
        }
        if ($application->receipts()->count() !== 2) {
            throw new \RuntimeException('Expected one receipt per successful payment.');
        }
        if ($payments->admit($application->refresh(), $actor)->id !== $member->id) {
            throw new \RuntimeException('Repeated admission created a duplicate member.');
        }

        $this->info('Sprint 3 invoicing, payment rejection, receipts and admission verified successfully.');
        return 0;
    } finally {
        DB::rollBack();
    }
})->purpose('Exercise Sprint 3 invoice idempotency, partial/full payment, rejection rules, receipts and paid-member admission lifecycle.');
