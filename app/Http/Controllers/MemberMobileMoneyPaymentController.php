<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FinancialLedger;
use App\Models\Member;
use App\Services\MemberPortalService;
use App\Services\MobileMoneyPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MemberMobileMoneyPaymentController extends Controller
{
    public function __construct(
        private readonly MemberPortalService $portal,
        private readonly MobileMoneyPaymentService $payments,
    ) {}

    public function __invoke(Request $request, Member $member): RedirectResponse
    {
        $this->portal->assertAccess($request->user(), $member, ['owner', 'representative', 'billing']);
        $validated = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:financial_ledger,id'],
            'msisdn' => ['required', 'string', 'max:32'],
        ]);

        $invoice = FinancialLedger::query()
            ->whereKey((int) $validated['invoice_id'])
            ->where('member_id', $member->id)
            ->where('type', 'invoice')
            ->with('settlements')
            ->firstOrFail();

        if ((float) $invoice->balance_due <= 0.0001) {
            throw ValidationException::withMessages(['invoice_id' => 'This invoice is already fully settled.']);
        }

        $payment = $this->payments->initiateMtn($invoice, $validated['msisdn'], $request->user());

        return redirect()->route('member.billing', $member)
            ->with('status', 'MTN MoMo request '.$payment->external_reference.' was sent. Approve it on the phone; the portal will update after provider verification.');
    }
}
