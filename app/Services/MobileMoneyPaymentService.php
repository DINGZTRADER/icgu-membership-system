<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialLedger;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MobileMoneyPaymentService
{
    public function __construct(
        private readonly MtnMomoClient $mtn,
        private readonly MembershipPaymentService $applicationPayments,
        private readonly MembershipRenewalService $renewalPayments,
        private readonly SystemActorService $systemActors,
        private readonly AuditService $audit,
    ) {}

    public function initiateMtn(FinancialLedger $invoice, string $msisdn, ?User $actor = null): PaymentRequest
    {
        if (! $this->mtn->isEnabled()) {
            throw ValidationException::withMessages(['provider' => 'MTN MoMo payments are not enabled.']);
        }

        $payer = $this->normalizeUgandaMsisdn($msisdn);

        $paymentRequest = DB::transaction(function () use ($invoice, $payer, $actor): PaymentRequest {
            $lockedInvoice = FinancialLedger::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $lockedInvoice->loadMissing(['settlements', 'application', 'renewal', 'member']);

            if ($lockedInvoice->type !== 'invoice') {
                throw ValidationException::withMessages(['invoice' => 'Mobile Money can only settle an invoice.']);
            }

            $balance = (float) $lockedInvoice->balance_due;
            if ($balance <= 0.0001) {
                throw ValidationException::withMessages(['invoice' => 'Invoice is already fully settled.']);
            }

            if (strtoupper((string) $lockedInvoice->currency) !== strtoupper((string) config('services.mtn_momo.currency', 'UGX'))) {
                throw ValidationException::withMessages(['currency' => 'Invoice currency is not supported by the configured MTN MoMo collection account.']);
            }

            $existing = PaymentRequest::query()
                ->where('provider', 'mtn_momo')
                ->where('invoice_id', $lockedInvoice->id)
                ->where('payer_msisdn', $payer)
                ->pending()
                ->where('created_at', '>=', now()->subMinutes(10))
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return PaymentRequest::query()->create([
                'provider' => 'mtn_momo',
                'external_reference' => (string) Str::uuid(),
                'invoice_id' => $lockedInvoice->id,
                'membership_application_id' => $lockedInvoice->membership_application_id,
                'membership_renewal_id' => $lockedInvoice->membership_renewal_id,
                'member_id' => $lockedInvoice->member_id,
                'amount' => $balance,
                'currency' => strtoupper((string) $lockedInvoice->currency),
                'payer_msisdn' => $payer,
                'status' => 'created',
                'created_by' => $actor?->id,
            ]);
        });

        if ($paymentRequest->status !== 'created') {
            return $paymentRequest;
        }

        try {
            $this->mtn->requestToPay(
                $paymentRequest->external_reference,
                number_format((float) $paymentRequest->amount, 0, '.', ''),
                $paymentRequest->currency,
                $paymentRequest->payer_msisdn,
                $paymentRequest->invoice?->invoice_number ?? 'ICGU-MEMBERSHIP',
                'ICGU membership payment',
                'Institute of Corporate Governance Uganda membership fee',
            );

            $paymentRequest->forceFill([
                'status' => 'pending',
                'provider_status' => 'PENDING',
                'requested_at' => now(),
            ])->save();

            $this->audit->record('mobile_money_request_initiated', $paymentRequest, after: [
                'provider' => 'mtn_momo',
                'external_reference' => $paymentRequest->external_reference,
                'invoice_id' => $paymentRequest->invoice_id,
                'amount' => $paymentRequest->amount,
                'currency' => $paymentRequest->currency,
            ]);
        } catch (\Throwable $exception) {
            $paymentRequest->forceFill([
                'status' => 'failed',
                'failure_reason' => Str::limit($exception->getMessage(), 500, ''),
                'completed_at' => now(),
            ])->save();
            throw $exception;
        }

        return $paymentRequest->refresh();
    }

    public function reconcile(PaymentRequest $paymentRequest): PaymentRequest
    {
        if ($paymentRequest->provider !== 'mtn_momo') {
            throw new RuntimeException('Unsupported payment-request provider.');
        }
        if ($paymentRequest->is_terminal) {
            return $paymentRequest;
        }

        $provider = $this->mtn->paymentStatus($paymentRequest->external_reference);
        $providerStatus = strtoupper((string) ($provider['status'] ?? 'UNKNOWN'));

        return DB::transaction(function () use ($paymentRequest, $provider, $providerStatus): PaymentRequest {
            $locked = PaymentRequest::query()->whereKey($paymentRequest->id)->lockForUpdate()->firstOrFail();
            if ($locked->is_terminal) {
                return $locked;
            }

            $locked->forceFill([
                'provider_status' => $providerStatus,
                'provider_transaction_id' => isset($provider['financialTransactionId']) ? (string) $provider['financialTransactionId'] : $locked->provider_transaction_id,
                'provider_payload' => $provider,
                'last_polled_at' => now(),
            ])->save();

            if ($providerStatus === 'PENDING') {
                $locked->forceFill(['status' => 'pending'])->save();
                return $locked;
            }

            if ($providerStatus !== 'SUCCESSFUL') {
                $reason = (string) ($provider['reason'] ?? $provider['message'] ?? 'Provider reported a failed payment.');
                $locked->forceFill([
                    'status' => 'failed',
                    'failure_reason' => Str::limit($reason, 500, ''),
                    'completed_at' => now(),
                ])->save();
                return $locked;
            }

            $providerAmount = isset($provider['amount']) ? (float) $provider['amount'] : null;
            $providerCurrency = isset($provider['currency']) ? strtoupper((string) $provider['currency']) : null;
            if ($providerAmount === null || abs($providerAmount - (float) $locked->amount) > 0.0001 || $providerCurrency !== strtoupper($locked->currency)) {
                $locked->forceFill([
                    'status' => 'failed',
                    'failure_reason' => 'Provider amount or currency did not match the original payment request.',
                    'completed_at' => now(),
                ])->save();
                throw new RuntimeException('MTN MoMo settlement mismatch detected.');
            }

            $transactionReference = $locked->provider_transaction_id ?: 'MTN-'.$locked->external_reference;
            $existingLedger = FinancialLedger::query()->where('tx_reference', $transactionReference)->first();

            if ($existingLedger === null) {
                $invoice = FinancialLedger::query()->whereKey($locked->invoice_id)->with('settlements')->lockForUpdate()->firstOrFail();
                $actor = $this->systemActors->integrations();

                if ($locked->membership_application_id !== null) {
                    $application = $locked->application()->firstOrFail();
                    $this->applicationPayments->recordPayment(
                        $application,
                        $invoice,
                        (string) $locked->amount,
                        'mobile_money',
                        $transactionReference,
                        $actor,
                        'MTN MoMo',
                        now(),
                    );
                } elseif ($locked->membership_renewal_id !== null) {
                    $renewal = $locked->renewal()->firstOrFail();
                    $this->renewalPayments->recordPayment(
                        $renewal,
                        (string) $locked->amount,
                        'mobile_money',
                        $transactionReference,
                        $actor,
                        'MTN MoMo',
                        now(),
                    );
                } else {
                    throw new RuntimeException('Payment request is not linked to a supported membership billing context.');
                }
            }

            $locked->forceFill([
                'status' => 'successful',
                'failure_reason' => null,
                'completed_at' => now(),
            ])->save();

            $this->audit->record('mobile_money_payment_verified', $locked, after: [
                'provider' => 'mtn_momo',
                'external_reference' => $locked->external_reference,
                'provider_transaction_id' => $locked->provider_transaction_id,
                'invoice_id' => $locked->invoice_id,
                'amount' => $locked->amount,
                'currency' => $locked->currency,
            ]);

            return $locked;
        });
    }

    /** @return array{processed:int, successful:int, failed:int, pending:int} */
    public function reconcilePending(int $limit = 50): array
    {
        $processed = $successful = $failed = $pending = 0;

        PaymentRequest::query()
            ->where('provider', 'mtn_momo')
            ->pending()
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->each(function (PaymentRequest $request) use (&$processed, &$successful, &$failed, &$pending): void {
                $processed++;
                try {
                    $result = $this->reconcile($request);
                    match ($result->status) {
                        'successful' => $successful++,
                        'failed' => $failed++,
                        default => $pending++,
                    };
                } catch (\Throwable) {
                    $failed++;
                }
            });

        return compact('processed', 'successful', 'failed', 'pending');
    }

    private function normalizeUgandaMsisdn(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (str_starts_with($digits, '0')) {
            $digits = '256'.substr($digits, 1);
        } elseif (strlen($digits) === 9 && str_starts_with($digits, '7')) {
            $digits = '256'.$digits;
        }

        if (! preg_match('/^2567\d{8}$/', $digits)) {
            throw ValidationException::withMessages(['msisdn' => 'Enter a valid Ugandan mobile number.']);
        }

        return $digits;
    }
}
