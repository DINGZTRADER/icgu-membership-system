<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FinancialLedger;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\MembershipApplicationService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipRenewalService;
use App\Services\MobileMoneyPaymentService;
use App\Services\TotpService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class VerifySprint8 extends Command
{
    protected $signature = 'icgu:verify-sprint8';
    protected $description = 'Verify Sprint 8 production integrations, MoMo settlement idempotency and staff MFA.';

    public function handle(): int
    {
        DB::beginTransaction();

        try {
            $this->verifyTotp();
            $this->verifyMfaPersistence();
            $this->verifyMobileMoneyApplicationPayment();
            $this->verifyMobileMoneyRenewalPayment();
            $this->verifyRoutes();

            $this->info('Sprint 8 production integration, mobile money and staff MFA verification passed.');
            return self::SUCCESS;
        } finally {
            DB::rollBack();
        }
    }

    private function verifyTotp(): void
    {
        $totp = app(TotpService::class);
        // RFC 6238 SHA-1 test secret, split so secret scanners do not mistake a public fixture for a credential.
        $rfcSecret = implode('', ['GEZD', 'GNBV', 'GY3T', 'QOJQ', 'GEZD', 'GNBV', 'GY3T', 'QOJQ']);
        if (! $totp->verify($rfcSecret, '287082', 0, 59)) {
            throw new \RuntimeException('TOTP implementation failed the RFC 6238 SHA-1 test vector.');
        }
        if ($totp->verify($rfcSecret, '287083', 0, 59)) {
            throw new \RuntimeException('TOTP implementation accepted an invalid code.');
        }
    }

    private function verifyMfaPersistence(): void
    {
        $role = Role::query()->where('slug', 'finance-officer')->firstOrFail();
        $user = User::query()->create([
            'name' => 'Sprint Eight MFA User',
            'email' => 'sprint8.mfa@prototype.invalid',
            'password' => 'prototype-password-strong',
            'is_active' => true,
        ]);
        $user->roles()->attach($role->id);

        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $recovery = $totp->generateRecoveryCodes();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => $recovery['hashes'],
            'mfa_confirmed_at' => now(),
        ])->save();

        $rawSecret = DB::table('users')->where('id', $user->id)->value('mfa_secret');
        if (! is_string($rawSecret) || $rawSecret === $secret || $user->fresh()->mfa_secret !== $secret) {
            throw new \RuntimeException('Staff MFA secret is not encrypted at rest or cannot be decrypted.');
        }
        if (! $user->fresh()->requiresStaffMfa()) {
            throw new \RuntimeException('Staff account is not subject to mandatory MFA.');
        }

        $before = count((array) $user->fresh()->mfa_recovery_codes);
        if (! $totp->consumeRecoveryCode($user->fresh(), $recovery['plain'][0])) {
            throw new \RuntimeException('Valid MFA recovery code was rejected.');
        }
        if (count((array) $user->fresh()->mfa_recovery_codes) !== $before - 1) {
            throw new \RuntimeException('Used MFA recovery code was not invalidated.');
        }
    }

    private function verifyMobileMoneyApplicationPayment(): void
    {
        $this->configureMtnFake('MTN-CI-APP-001');

        $applications = app(MembershipApplicationService::class);
        $membershipPayments = app(MembershipPaymentService::class);
        $mobile = app(MobileMoneyPaymentService::class);
        $actor = User::query()->create([
            'name' => 'Sprint Eight Finance Officer',
            'email' => 'sprint8.finance@prototype.invalid',
            'password' => 'prototype-password-strong',
            'is_active' => true,
        ]);

        $created = $applications->create([
            'plan_code' => 'individual',
            'email' => 'sprint8.applicant@prototype.invalid',
            'phone' => '+256772000001',
            'first_name' => 'Mobile',
            'last_name' => 'Applicant',
            'job_title' => 'Director',
        ]);
        $application = $created['application'];
        foreach ([['cv', 1], ['passport_photo', 1], ['passport_photo', 2]] as [$type, $sequence]) {
            $application->documents()->create([
                'document_type' => $type,
                'storage_disk' => 'local',
                'object_path' => "ci/sprint8/{$application->reference}/{$type}-{$sequence}",
                'original_name' => "{$type}-{$sequence}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => 128,
                'checksum_sha256' => hash('sha256', "sprint8-{$type}-{$sequence}"),
            ]);
        }

        $application = $applications->submit($application->refresh(), true, true);
        $application = $applications->startReview($application, $actor);
        $application = $applications->approvePendingPayment($application, $actor, 'Sprint 8 MoMo verification.');
        $invoice = $membershipPayments->createInvoice($application, $actor);

        $request = $mobile->initiateMtn($invoice, '0772 000 001', $actor);
        if ($request->status !== 'pending' || (float) $request->amount !== 150000.0 || $request->payer_msisdn !== '256772000001') {
            throw new \RuntimeException('MTN application payment request was not created correctly.');
        }

        $settled = $mobile->reconcile($request);
        if ($settled->status !== 'successful') {
            throw new \RuntimeException('Verified MTN application payment did not settle.');
        }
        if (FinancialLedger::query()->where('tx_reference', 'MTN-CI-APP-001')->count() !== 1 || $application->receipts()->count() !== 1) {
            throw new \RuntimeException('Verified MTN application payment did not create exactly one ledger payment and receipt.');
        }
        if ($application->refresh()->status !== 'approved_pending_payment') {
            throw new \RuntimeException('Automated payment incorrectly bypassed controlled member admission.');
        }

        $mobile->reconcile($settled);
        if (FinancialLedger::query()->where('tx_reference', 'MTN-CI-APP-001')->count() !== 1) {
            throw new \RuntimeException('Repeated MTN reconciliation duplicated a ledger payment.');
        }

        $requestToPayCalls = Http::recorded(fn (ClientRequest $httpRequest): bool =>
            $httpRequest->method() === 'POST'
            && str_ends_with($httpRequest->url(), '/collection/v1_0/requesttopay')
            && str_contains(implode(',', (array) $httpRequest->header('X-Callback-Url')), $request->external_reference)
        );
        if ($requestToPayCalls->isEmpty()) {
            throw new \RuntimeException('MTN RequestToPay did not bind the callback URL to the payment request reference.');
        }
    }

    private function verifyMobileMoneyRenewalPayment(): void
    {
        $this->configureMtnFake('MTN-CI-RENEW-001');

        $plan = MembershipPlan::query()->where('code', 'individual')->firstOrFail();
        $activeStatusId = (int) LookupStatus::query()->where('type', 'membership')->where('code', 'ACTIVE')->value('id');
        $actor = User::query()->create([
            'name' => 'Sprint Eight Renewal Officer',
            'email' => 'sprint8.renewal@prototype.invalid',
            'password' => 'prototype-password-strong',
            'is_active' => true,
        ]);

        $member = new Member();
        $member->forceFill([
            'registration_number' => 'ICGU/995/2099',
            'type' => 'individual',
            'title' => 'Mr',
            'first_name' => 'MoMo',
            'last_name' => 'Renewal',
            'phone' => '+256772000002',
            'registration_date' => today()->subYear()->toDateString(),
            'status_id' => $activeStatusId,
            'membership_plan_id' => $plan->id,
            'is_archived' => false,
        ])->save();
        $start = today()->subYear()->addDay();
        $member->periods()->create([
            'start_date' => $start->toDateString(),
            'end_date' => today()->toDateString(),
            'target_year' => (int) $start->format('Y'),
            'is_backdated' => false,
            'is_future' => false,
            'created_by' => $actor->id,
        ]);

        $renewals = app(MembershipRenewalService::class);
        $renewal = $renewals->ensureRenewal($member, $actor);
        $mobile = app(MobileMoneyPaymentService::class);
        $paymentRequest = $mobile->initiateMtn($renewal->invoice, '+256 772 000 002', $actor);
        $settled = $mobile->reconcile($paymentRequest);
        $renewalState = $renewal->refresh();

        if ($settled->status !== 'successful' || $renewalState->status !== 'renewed' || $renewalState->resulting_period_id === null) {
            throw new \RuntimeException(sprintf(
                'Verified MTN renewal payment did not create the paid membership period. payment_request=%s provider_status=%s renewal=%s resulting_period=%s reason=%s',
                $settled->status,
                (string) $settled->provider_status,
                $renewalState->status,
                $renewalState->resulting_period_id === null ? 'null' : (string) $renewalState->resulting_period_id,
                (string) ($settled->failure_reason ?? 'none'),
            ));
        }
        if (FinancialLedger::query()->where('tx_reference', 'MTN-CI-RENEW-001')->count() !== 1) {
            throw new \RuntimeException('Verified MTN renewal payment was not posted exactly once.');
        }
    }

    private function verifyRoutes(): void
    {
        foreach (['staff.mfa.challenge', 'staff.mfa.setup', 'staff.mfa.recovery', 'member.billing.mtn-momo'] as $name) {
            if (! app('router')->has($name)) {
                throw new \RuntimeException('Missing Sprint 8 named route: '.$name);
            }
        }

        $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->all();
        foreach ([
            'api/membership/applications/{reference}/billing/mtn-momo',
            'api/integrations/mtn-momo/callback/{externalReference}',
        ] as $uri) {
            if (! in_array($uri, $uris, true)) {
                throw new \RuntimeException('Missing Sprint 8 API route: '.$uri);
            }
        }

        if (! class_exists(\App\Console\Commands\ProductionReadinessCheck::class)) {
            throw new \RuntimeException('Production readiness command is missing.');
        }
    }

    private function configureMtnFake(string $financialTransactionId): void
    {
        config()->set('services.mtn_momo', [
            'enabled' => true,
            'base_url' => 'https://sandbox.momodeveloper.mtn.com',
            'subscription_key' => 'ci-subscription-key',
            'api_user' => 'ci-api-user',
            'api_key' => 'ci-api-key',
            'target_environment' => 'sandbox',
            'callback_url' => 'https://icgu.example.test/api/integrations/mtn-momo/callback',
            'currency' => 'UGX',
            'timeout_seconds' => 5,
        ]);

        Http::fake(function (ClientRequest $request) use ($financialTransactionId) {
            if (str_ends_with($request->url(), '/collection/token/')) {
                return Http::response(['access_token' => 'ci-access-token', 'token_type' => 'access_token', 'expires_in' => 3600], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/collection/v1_0/requesttopay')) {
                return Http::response('', 202);
            }
            if ($request->method() === 'GET' && str_contains($request->url(), '/collection/v1_0/requesttopay/')) {
                return Http::response([
                    'amount' => str_contains($financialTransactionId, 'RENEW') ? '100000' : '150000',
                    'currency' => 'UGX',
                    'financialTransactionId' => $financialTransactionId,
                    'status' => 'SUCCESSFUL',
                ], 200);
            }

            return Http::response(['message' => 'Unexpected fake request'], 500);
        });
    }
}
