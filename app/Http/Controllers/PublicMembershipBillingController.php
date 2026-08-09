<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipApplication;
use App\Services\MembershipApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicMembershipBillingController extends Controller
{
    public function __construct(private readonly MembershipApplicationService $applications) {}

    public function show(Request $request, string $reference): JsonResponse
    {
        $application = MembershipApplication::query()
            ->where('reference', $reference)
            ->with(['invoice.settlements', 'payments.receipt', 'receipts'])
            ->firstOrFail();

        $this->applications->authorizeToken($application, (string) $request->header('X-Application-Token', ''));

        return response()->json(['data' => [
            'application_reference' => $application->reference,
            'application_status' => $application->status,
            'invoice' => $application->invoice ? [
                'invoice_number' => $application->invoice->invoice_number,
                'amount' => $application->invoice->amount,
                'currency' => $application->invoice->currency,
                'balance_due' => $application->invoice->balance_due,
                'due_date' => $application->invoice->due_date,
                'is_fully_settled' => $application->invoice->is_fully_settled,
            ] : null,
            'payments' => $application->payments->map(fn ($payment) => [
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'payment_method' => $payment->payment_method,
                'transaction_reference' => $payment->tx_reference,
                'received_at' => $payment->received_at,
                'receipt_number' => $payment->receipt?->receipt_number,
            ])->values(),
            'membership_registration_number' => $application->resultingMember?->registration_number,
        ]]);
    }
}
