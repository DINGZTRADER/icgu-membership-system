<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FinancialLedger;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MembershipRenewalService;
use App\Services\MobileMoneyPaymentService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class VerifySprint8PaymentRace extends Command
{
    protected $signature = 'icgu:verify-sprint8-payment-race';
    protected $description = 'Verify successful MoMo charges are sent to manual reconciliation if the invoice balance changes while the request is pending.';

    public function handle(): int
    {
        DB::beginTransaction();

        try {
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

            Http::fake(function (ClientRequest $request) {
                if (str_ends_with($request->url(), '/collection/token/')) {
                    return Http::response(['access_token' => 'ci-access-token', 'expires_in' => 3600], 200);
                }
                if ($request->method() === 'POST' && str_ends_with($request->url(), '/collection/v1_0/requesttopay')) {
                    return Http::response('', 202);
                }
                if ($request->method() === 'GET' && str_contains($request->url(), '/collection/v1_0/requesttopay/')) {
                    return Http::response([
                        'amount' => '100000',
                        'currency' => 'UGX',
                        'financialTransactionId' => 'MTN-CI-RACE-001',
                        'status' => 'SUCCESSFUL',
                    ], 200);
                }

                return Http::response(['message' => 'Unexpected fake request'], 500);
            });

            $plan = MembershipPlan::query()->where('code', 'individual')->firstOrFail();
            $activeStatusId = (int) LookupStatus::query()->where('type', 'membership')->where('code', 'ACTIVE')->value('id');
            $actor = User::query()->create([
                'name' => 'Sprint Eight Race Officer',
                'email' => 'sprint8.race@prototype.invalid',
                'password' => 'prototype-password-strong',
                'is_active' => true,
            ]);

            $member = new Member();
            $member->forceFill([
                'registration_number' => 'ICGU/994/2099',
                'type' => 'individual',
                'title' => 'Ms',
                'first_name' => 'Race',
                'last_name' => 'Verifier',
                'phone' => '+256772000003',
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
            $request = $mobile->initiateMtn($renewal->invoice, '0772 000 003', $actor);

            // A second channel changes the outstanding balance while the MTN prompt is still pending.
            $renewals->recordPayment(
                $renewal,
                '50000',
                'bank_transfer',
                'CI-RACE-MANUAL-001',
                $actor,
                'CI Bank',
                now(),
            );

            $result = $mobile->reconcile($request);
            if ($result->status !== 'review_required') {
                throw new \RuntimeException('Successful MTN charge with a changed invoice balance was not sent to manual reconciliation.');
            }
            if ($result->provider_status !== 'SUCCESSFUL' || $result->provider_transaction_id !== 'MTN-CI-RACE-001') {
                throw new \RuntimeException('Manual-review state did not preserve the verified MTN settlement evidence.');
            }
            if (FinancialLedger::query()->where('tx_reference', 'MTN-CI-RACE-001')->exists()) {
                throw new \RuntimeException('MTN race condition incorrectly created an automatic overpayment ledger entry.');
            }
            if (FinancialLedger::query()->where('tx_reference', 'CI-RACE-MANUAL-001')->count() !== 1) {
                throw new \RuntimeException('Control manual payment is missing from the ledger.');
            }

            $second = $mobile->reconcile($result);
            if ($second->status !== 'review_required' || FinancialLedger::query()->where('tx_reference', 'MTN-CI-RACE-001')->exists()) {
                throw new \RuntimeException('Manual-review MTN request was not terminal/idempotent.');
            }

            $this->info('Sprint 8 MoMo balance-change race handling verified successfully.');
            return self::SUCCESS;
        } finally {
            DB::rollBack();
        }
    }
}
