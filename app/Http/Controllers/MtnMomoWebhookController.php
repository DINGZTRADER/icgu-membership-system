<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ReconcileMobileMoneyPayment;
use App\Models\PaymentRequest;
use App\Models\PaymentWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MtnMomoWebhookController extends Controller
{
    public function __invoke(Request $request, string $externalReference): JsonResponse
    {
        $paymentRequest = PaymentRequest::query()
            ->where('provider', 'mtn_momo')
            ->where('external_reference', $externalReference)
            ->first();

        $headers = collect($request->headers->all())
            ->map(fn (array $values): string => implode(',', $values))
            ->sortKeys()
            ->all();

        $payload = $request->json()->all();
        if ($payload === [] && trim((string) $request->getContent()) !== '') {
            $payload = ['raw_body_sha256' => hash('sha256', (string) $request->getContent())];
        }

        $event = PaymentWebhookEvent::query()->create([
            'payment_request_id' => $paymentRequest?->id,
            'provider' => 'mtn_momo',
            'external_reference' => $externalReference,
            'source_ip' => $request->ip(),
            'http_method' => $request->method(),
            'headers_sha256' => hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR)),
            'payload' => $payload,
            'processing_status' => $paymentRequest === null ? 'ignored' : 'received',
            'processing_error' => $paymentRequest === null ? 'Unknown payment reference.' : null,
            'received_at' => now(),
            'processed_at' => $paymentRequest === null ? now() : null,
        ]);

        if ($paymentRequest !== null) {
            $paymentRequest->forceFill(['callback_received_at' => now()])->save();
            ReconcileMobileMoneyPayment::dispatch($paymentRequest->id, $event->id);
        }

        return response()->json(['accepted' => true], 202);
    }
}
