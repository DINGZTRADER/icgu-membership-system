<?php

declare(strict_types=1);

use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipApplicationService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipRenewalService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
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

Artisan::command('icgu:verify-sprint4', function (): int {
    DB::beginTransaction();

    try {
        $renewals = app(MembershipRenewalService::class);
        $plan = MembershipPlan::query()->where('code', 'individual')->firstOrFail();
        $activeStatusId = (int) LookupStatus::query()->where('type', 'membership')->where('code', 'ACTIVE')->value('id');
        $actor = User::query()->create([
            'name' => 'Sprint Four Renewal Officer',
            'email' => 'sprint4.renewals@prototype.invalid',
            'password' => 'prototype-password',
            'is_active' => true,
        ]);

        $member = new Member();
        $member->forceFill([
            'registration_number' => 'ICGU/998/2099',
            'type' => 'individual',
            'title' => 'Ms',
            'first_name' => 'Renewal',
            'last_name' => 'Arrears',
            'phone' => '+256700004004',
            'job_title' => 'Director',
            'registration_date' => today()->subYear()->toDateString(),
            'status_id' => $activeStatusId,
            'membership_plan_id' => $plan->id,
            'is_archived' => false,
        ])->save();
        $member->emails()->create([
            'email' => 'sprint4.arrears@prototype.invalid',
            'email_type' => 'billing',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $source = $member->periods()->create([
            'start_date' => today()->subYear()->subDays(9)->toDateString(),
            'end_date' => today()->subDays(10)->toDateString(),
            'target_year' => (int) today()->subYear()->format('Y'),
            'is_backdated' => false,
            'is_future' => false,
            'created_by' => $actor->id,
        ]);

        $renewals->synchronizeMembershipStatuses($actor);
        if ($member->refresh()->status?->code !== 'EXPIRED') {
            throw new \RuntimeException('Expired period did not transition member to EXPIRED.');
        }

        $renewal = $renewals->ensureRenewal($member->refresh(), $actor);
        if ((float) $renewal->renewal_fee !== 100000.0 || (float) $renewal->invoice->amount !== 100000.0) {
            throw new \RuntimeException('Individual annual renewal fee is incorrect.');
        }
        if ((int) $renewal->source_period_id !== (int) $source->id || $renewal->invoice->membership_renewal_id !== $renewal->id) {
            throw new \RuntimeException('Renewal invoice is not correctly linked to the expiring period.');
        }
        if ($renewals->ensureRenewal($member->refresh(), $actor)->id !== $renewal->id) {
            throw new \RuntimeException('Duplicate renewal cycle was created for one membership year.');
        }
        if ($renewal->resulting_period_id !== null) {
            throw new \RuntimeException('Membership entitlement was created before renewal payment.');
        }

        $partial = $renewals->recordPayment($renewal, '40000', 'mobile_money', 'SPRINT4-PAY-001', $actor, 'prototype-mobile-money');
        if ((float) $partial['balance_due'] !== 60000.0 || $partial['renewal']->status !== 'partial') {
            throw new \RuntimeException('Partial renewal payment was not recorded correctly.');
        }
        if ($partial['renewal']->resulting_period_id !== null) {
            throw new \RuntimeException('Partial renewal payment incorrectly created a membership period.');
        }

        $overpaymentRejected = false;
        try {
            $renewals->recordPayment($renewal->refresh(), '60001', 'cash', 'SPRINT4-OVERPAY', $actor);
        } catch (ValidationException) {
            $overpaymentRejected = true;
        }
        if (! $overpaymentRejected) {
            throw new \RuntimeException('Renewal overpayment was incorrectly accepted.');
        }

        $final = $renewals->recordPayment($renewal->refresh(), '60000', 'bank_transfer', 'SPRINT4-PAY-002', $actor, 'prototype-bank');
        if ((float) $final['balance_due'] !== 0.0 || $final['renewal']->status !== 'renewed') {
            throw new \RuntimeException('Fully paid renewal did not reach RENEWED state.');
        }
        $renewedPeriod = $final['renewal']->resultingPeriod;
        if ($renewedPeriod === null || ! $renewedPeriod->is_backdated || $renewedPeriod->is_future) {
            throw new \RuntimeException('Late renewal did not create the expected backdated paid period.');
        }
        if ($member->refresh()->status?->code !== 'ACTIVE') {
            throw new \RuntimeException('Expired member was not reactivated after paying a renewal covering today.');
        }
        if ($member->periods()->count() !== 2) {
            throw new \RuntimeException('Renewal created an unexpected number of membership periods.');
        }

        $earlyMember = new Member();
        $earlyMember->forceFill([
            'registration_number' => 'ICGU/997/2099',
            'type' => 'individual',
            'title' => 'Mr',
            'first_name' => 'Early',
            'last_name' => 'Renewal',
            'registration_date' => today()->subYear()->toDateString(),
            'status_id' => $activeStatusId,
            'membership_plan_id' => $plan->id,
            'is_archived' => false,
        ])->save();
        $earlyMember->emails()->create([
            'email' => 'sprint4.early@prototype.invalid',
            'email_type' => 'billing',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $earlyMember->periods()->create([
            'start_date' => today()->subYear()->addDay()->toDateString(),
            'end_date' => today()->toDateString(),
            'target_year' => (int) today()->format('Y'),
            'is_backdated' => false,
            'is_future' => false,
            'created_by' => $actor->id,
        ]);
        $earlyRenewal = $renewals->ensureRenewal($earlyMember, $actor);
        $earlyPaid = $renewals->recordPayment($earlyRenewal, '100000', 'card', 'SPRINT4-PAY-003', $actor, 'prototype-card');
        if ($earlyPaid['renewal']->resultingPeriod === null || ! $earlyPaid['renewal']->resultingPeriod->is_future) {
            throw new \RuntimeException('Early renewal did not create a future paid membership period.');
        }
        if ($earlyMember->refresh()->status?->code !== 'ACTIVE') {
            throw new \RuntimeException('Early renewal incorrectly changed a currently active member.');
        }

        $reminderMember = new Member();
        $reminderMember->forceFill([
            'registration_number' => 'ICGU/996/2099',
            'type' => 'individual',
            'title' => 'Dr',
            'first_name' => 'Reminder',
            'last_name' => 'Test',
            'registration_date' => today()->subYear()->toDateString(),
            'status_id' => $activeStatusId,
            'membership_plan_id' => $plan->id,
            'is_archived' => false,
        ])->save();
        $reminderMember->emails()->create([
            'email' => 'sprint4.reminder@prototype.invalid',
            'email_type' => 'billing',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $reminderMember->periods()->create([
            'start_date' => today()->subYear()->addDays(6)->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'target_year' => (int) today()->format('Y'),
            'is_backdated' => false,
            'is_future' => false,
            'created_by' => $actor->id,
        ]);
        $renewals->ensureRenewal($reminderMember, $actor);
        if (Artisan::call('icgu:dispatch-reminders', ['--dry-run' => true, '--limit' => 50]) !== 0) {
            throw new \RuntimeException('Renewal reminder dry-run failed.');
        }
        if ($reminderMember->communicationLogs()->exists()) {
            throw new \RuntimeException('Reminder dry-run unexpectedly wrote communication logs.');
        }

        $this->info('Sprint 4 renewals, arrears, expiry, reactivation and reminder dry-run verified successfully.');
        return 0;
    } finally {
        DB::rollBack();
    }
})->purpose('Exercise Sprint 4 annual renewals, arrears, expiry, reactivation, early renewal and reminder safety.');

Schedule::command('icgu:process-renewals --days-ahead=30')
    ->dailyAt('07:00')
    ->timezone('Africa/Kampala')
    ->withoutOverlapping();

Schedule::command('icgu:dispatch-reminders')
    ->dailyAt('08:00')
    ->timezone('Africa/Kampala')
    ->withoutOverlapping();
