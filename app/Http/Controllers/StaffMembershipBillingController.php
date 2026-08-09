<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FinancialLedger;
use App\Models\MembershipApplication;
use App\Models\Receipt;
use App\Services\MembershipPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffMembershipBillingController extends Controller
{
    public function __construct(private readonly MembershipPaymentService $payments) {}

    public function show(string $reference): JsonResponse
    {
        $application = $this->application($reference);
        $application->load(['invoice.settlements', 'payments.receipt', 'receipts', 'resultingMember']);

        return response()->json(['data' => [
            'application_reference' => $application->reference,
            'application_status' => $application->status,
            'invoice' => $application->invoice,
            'balance_due' => $application->invoice?->balance_due,
            'payments' => $application->payments,
            'receipts' => $application->receipts,
            'member' => $application->resultingMember,
        ]]);
    }

    public function invoice(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate(['due_date' => ['nullable', 'date']]);
        $invoice = $this->payments->createInvoice(
            $this->application($reference),
            $request->user(),
            isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
        );

        return response()->json(['data' => $invoice], 201);
    }

    public function payment(Request $request, string $reference): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:financial_ledger,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'mobile_money', 'cash', 'card', 'cheque', 'other'])],
            'transaction_reference' => ['required', 'string', 'max:100', 'unique:financial_ledger,tx_reference'],
            'payment_provider' => ['nullable', 'string', 'max:80'],
            'received_at' => ['nullable', 'date'],
        ]);

        $result = $this->payments->recordPayment(
            $this->application($reference),
            FinancialLedger::query()->findOrFail((int) $validated['invoice_id']),
            (string) $validated['amount'],
            $validated['payment_method'],
            $validated['transaction_reference'],
            $request->user(),
            $validated['payment_provider'] ?? null,
            isset($validated['received_at']) ? new \DateTimeImmutable($validated['received_at']) : null,
        );

        return response()->json(['data' => $result], 201);
    }

    public function admit(Request $request, string $reference): JsonResponse
    {
        $member = $this->payments->admit($this->application($reference), $request->user());
        return response()->json(['data' => $member], 201);
    }

    public function receipt(Receipt $receipt): JsonResponse
    {
        return response()->json(['data' => $receipt->load(['payment', 'application', 'member'])]);
    }

    private function application(string $reference): MembershipApplication
    {
        return MembershipApplication::query()->where('reference', $reference)->with(['plan', 'organisation', 'representatives', 'documents'])->firstOrFail();
    }
}
