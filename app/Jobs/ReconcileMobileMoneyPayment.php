<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PaymentRequest;
use App\Models\PaymentWebhookEvent;
use App\Services\MobileMoneyPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class ReconcileMobileMoneyPayment implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $uniqueFor = 60;

    public function __construct(
        public readonly int $paymentRequestId,
        public readonly ?int $webhookEventId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'payment-request:'.$this->paymentRequestId;
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(MobileMoneyPaymentService $payments): void
    {
        $event = $this->webhookEventId !== null ? PaymentWebhookEvent::query()->find($this->webhookEventId) : null;
        $request = PaymentRequest::query()->find($this->paymentRequestId);

        if ($request === null) {
            $event?->forceFill([
                'processing_status' => 'ignored',
                'processing_error' => 'Payment request no longer exists.',
                'processed_at' => now(),
            ])->save();
            return;
        }

        if ($request->is_terminal) {
            $event?->forceFill([
                'processing_status' => 'verified',
                'processed_at' => now(),
            ])->save();
            return;
        }

        try {
            $payments->reconcile($request);
            $event?->forceFill([
                'processing_status' => 'verified',
                'processed_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            $event?->forceFill([
                'processing_status' => 'failed',
                'processing_error' => Str::limit($exception->getMessage(), 1000, ''),
                'processed_at' => now(),
            ])->save();
            throw $exception;
        }
    }
}
