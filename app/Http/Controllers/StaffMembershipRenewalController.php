<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MembershipRenewal;
use App\Services\MembershipRenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffMembershipRenewalController extends Controller
{
    public function __construct(private readonly MembershipRenewalService $renewals) {}

    public function show(Member $member): JsonResponse
    {
        $member->load([
            'status',
            'membershipPlan',
            'currentPeriod',
            'latestPeriod',
            'renewals.invoice.settlements',
            'renewals.resultingPeriod',
        ]);

        return response()->json(['data' => [
            'member' => $member,
            'outstanding_balance' => $member->outstanding_balance,
            'renewals' => $member->renewals,
        ]]);
    }

    public function invoice(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate(['due_date' => ['nullable', 'date']]);
        $renewal = $this->renewals->ensureRenewal(
            $member,
            $request->user(),
            isset($validated['due_date']) ? new \DateTimeImmutable($validated['due_date']) : null,
        );

        return response()->json(['data' => $renewal], 201);
    }

    public function payment(Request $request, Member $member, MembershipRenewal $renewal): JsonResponse
    {
        abort_unless((int) $renewal->member_id === (int) $member->id, 404);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::in(['bank_transfer', 'mobile_money', 'cash', 'card', 'cheque', 'other'])],
            'transaction_reference' => ['required', 'string', 'max:100', 'unique:financial_ledger,tx_reference'],
            'payment_provider' => ['nullable', 'string', 'max:80'],
            'received_at' => ['nullable', 'date'],
        ]);

        $result = $this->renewals->recordPayment(
            $renewal,
            (string) $validated['amount'],
            $validated['payment_method'],
            $validated['transaction_reference'],
            $request->user(),
            $validated['payment_provider'] ?? null,
            isset($validated['received_at']) ? new \DateTimeImmutable($validated['received_at']) : null,
        );

        return response()->json(['data' => $result], 201);
    }
}
