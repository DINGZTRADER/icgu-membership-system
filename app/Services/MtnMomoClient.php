<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MtnMomoClient
{
    /** @return array{accepted:bool,http_status:int} */
    public function requestToPay(string $referenceId, string $amount, string $currency, string $msisdn, string $externalId, string $payerMessage, string $payeeNote): array
    {
        $response = $this->authorizedRequest()
            ->withHeaders([
                'X-Reference-Id' => $referenceId,
                'X-Target-Environment' => $this->targetEnvironment(),
                'X-Callback-Url' => rtrim($this->callbackUrl(), '/').'/'.$referenceId,
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey(),
                'Content-Type' => 'application/json',
            ])
            ->post($this->baseUrl().'/collection/v1_0/requesttopay', [
                'amount' => $amount,
                'currency' => $currency,
                'externalId' => $externalId,
                'payer' => [
                    'partyIdType' => 'MSISDN',
                    'partyId' => $msisdn,
                ],
                'payerMessage' => $payerMessage,
                'payeeNote' => $payeeNote,
            ]);

        if ($response->status() !== 202) {
            throw new RuntimeException('MTN MoMo RequestToPay was not accepted (HTTP '.$response->status().').');
        }

        return ['accepted' => true, 'http_status' => 202];
    }

    /** @return array<string,mixed> */
    public function paymentStatus(string $referenceId): array
    {
        $response = $this->authorizedRequest()
            ->withHeaders([
                'X-Target-Environment' => $this->targetEnvironment(),
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey(),
            ])
            ->get($this->baseUrl().'/collection/v1_0/requesttopay/'.$referenceId);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to verify MTN MoMo payment status (HTTP '.$response->status().').');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('MTN MoMo returned an invalid payment-status payload.');
        }

        return $payload;
    }

    public function isEnabled(): bool
    {
        return (bool) config('services.mtn_momo.enabled', false);
    }

    private function authorizedRequest(): PendingRequest
    {
        $token = Cache::remember(
            'icgu:mtn-momo:token:'.hash('sha256', $this->apiUser()),
            now()->addMinutes(50),
            fn (): string => $this->fetchAccessToken(),
        );

        return Http::acceptJson()
            ->withToken($token)
            ->timeout($this->timeoutSeconds())
            ->retry(2, 250, throw: false);
    }

    private function fetchAccessToken(): string
    {
        $response = Http::acceptJson()
            ->withBasicAuth($this->apiUser(), $this->apiKey())
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $this->subscriptionKey()])
            ->timeout($this->timeoutSeconds())
            ->retry(2, 250, throw: false)
            ->post($this->baseUrl().'/collection/token/');

        if (! $response->successful()) {
            throw new RuntimeException('Unable to authenticate with MTN MoMo (HTTP '.$response->status().').');
        }

        $token = $response->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('MTN MoMo access-token response did not contain an access token.');
        }

        return $token;
    }

    private function baseUrl(): string
    {
        return rtrim($this->requiredConfig('base_url'), '/');
    }

    private function subscriptionKey(): string { return $this->requiredConfig('subscription_key'); }
    private function apiUser(): string { return $this->requiredConfig('api_user'); }
    private function apiKey(): string { return $this->requiredConfig('api_key'); }
    private function targetEnvironment(): string { return $this->requiredConfig('target_environment'); }
    private function callbackUrl(): string { return $this->requiredConfig('callback_url'); }
    private function timeoutSeconds(): int { return max(3, (int) config('services.mtn_momo.timeout_seconds', 15)); }

    private function requiredConfig(string $key): string
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('MTN MoMo integration is disabled.');
        }

        $value = config('services.mtn_momo.'.$key);
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('MTN MoMo configuration is incomplete: '.$key.'.');
        }

        return trim($value);
    }
}
