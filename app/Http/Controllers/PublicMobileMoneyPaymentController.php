<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipApplication;
use App\Models\PaymentRequest;
use App\Services\MembershipApplicationService;
use App\Services\MobileMoneyPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublicMobileMoneyPaymentController extends Controller
{
    public function __construct(
        private readonly MembershipApplicationService $applications,
        private readonly MobileMoneyPaymentService $payments,
    ) {}

    public function initiate(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate(['msisdn' => ['required', 'string', 'max:32']]);
        $application = $this->application($reference);
        $this->applications->authorizeToken($application, (string) $request->header('X-Application-Token', ''));

        if ($application->status !== 'approved_pending_payment' || $application->invoice === null) {
            throw ValidationException::withMessages(['application' => 'This application does not currently have a payable membership invoice.']);
        }

        $payment = $this->payments->initiateMtn($application->invoice, $validated['msisdn']);

        return response()->json(['data' => $this->payload($payment)], 202);
    }

    public function status(Request $request, string $reference, string $externalReference): JsonResponse
    {
        $application = $this->application($reference);
        $this->applications->authorizeToken($application, (string) $request->header('X-Application-Token', ''));

        $payment = PaymentRequest::query()
            ->where('external_reference', $externalReference)
            ->where('membership_application_id', $application->id)
            ->firstOrFail();

        return response()->json(['data' => $this->payload($payment)]);
    }

    private function application(string $reference): MembershipApplication
    {
        return MembershipApplication::query()
            ->where('reference', $reference)
            ->with(['invoice.settlements'])
            ->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function payload(PaymentRequest $payment): array
    {
        return [
            'provider' => $payment->provider,
            'reference' => $payment->external_reference,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'requested_at' => $payment->requested_at,
            'completed_at' => $payment->completed_at,
            'failure_reason' => $payment->status === 'failed' ? $payment->failure_reason : null,
        ];
    }
}
