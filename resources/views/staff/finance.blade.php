@extends('layouts.staff-admin')
@section('title','Finance')
@section('page-title','Finance & Receipts')
@section('content')
<div class="grid grid-4">
    <div class="card metric"><small>Outstanding</small><strong style="font-size:22px">UGX {{ number_format($summary['outstanding'],0) }}</strong></div>
    <div class="card metric"><small>Overdue invoices</small><strong>{{ $summary['overdue_count'] }}</strong></div>
    <div class="card metric"><small>Payments this month</small><strong style="font-size:22px">UGX {{ number_format($summary['payments_month'],0) }}</strong></div>
    <div class="card metric"><small>MoMo review queue</small><strong>{{ $paymentReviews->count() }}</strong><div class="delta">Provider-successful charges requiring Finance reconciliation</div></div>
</div>

@if($paymentReviews->isNotEmpty())
<section class="section card" style="border-color:#f0b35b">
    <div class="section-head"><div><h3>Mobile Money reconciliation required</h3><p>MTN verified these charges, but the local invoice changed or another domain guard prevented automatic posting. Do not ask the payer to pay again until Finance has reconciled the provider charge against the immutable ledger.</p></div><span class="status warn">{{ $paymentReviews->count() }} to review</span></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Verified</th><th>Provider reference</th><th>Invoice</th><th>Member / applicant</th><th>Amount</th><th>Reason</th></tr></thead><tbody>
    @foreach($paymentReviews as $review)
        @php($owner = $review->member?->display_name ?? ($review->application?->organisation?->legal_name ?? $review->application?->email ?? 'Membership payment'))
        <tr><td>{{ $review->completed_at?->format('d M Y H:i') ?? '—' }}</td><td class="mono">{{ $review->provider_transaction_id ?? $review->external_reference }}</td><td class="mono">{{ $review->invoice?->invoice_number ?? '—' }}</td><td>{{ $owner }}</td><td class="money">UGX {{ number_format((float)$review->amount,0) }}</td><td>{{ $review->failure_reason }}</td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endif

<section class="section"><div class="section-head"><div><h3>Invoice register</h3><p>Application and annual membership invoices with live append-only settlement balances.</p></div><form class="filters" method="GET"><div class="field"><label>State</label><select name="state">@foreach(['all','outstanding','overdue','paid'] as $option)<option value="{{ $option }}" @selected($state===$option)>{{ ucfirst($option) }}</option>@endforeach</select></div><button class="btn btn-primary" type="submit">Filter</button></form></div><div class="table-wrap"><table class="table"><thead><tr><th>Invoice</th><th>Owner</th><th>Fee</th><th>Due</th><th>Amount</th><th>Balance</th><th>Status</th></tr></thead><tbody>@forelse($invoices as $invoice)@php($owner=$invoice->member?->display_name ?? ($invoice->application?->organisation?->legal_name ?? $invoice->application?->email ?? 'Application'))<tr><td><strong class="mono">{{ $invoice->invoice_number }}</strong></td><td>{{ $owner }}</td><td>{{ ucwords(str_replace('_',' ',$invoice->fee_type)) }}</td><td>{{ $invoice->due_date?->format('d M Y') ?? '—' }}</td><td class="money">UGX {{ number_format((float)$invoice->amount,0) }}</td><td class="money">UGX {{ number_format((float)$invoice->balance_due,0) }}</td><td><span class="status {{ $invoice->is_fully_settled?'ok':($invoice->is_overdue?'bad':'warn') }}">{{ $invoice->is_fully_settled?'Paid':($invoice->is_overdue?'Overdue':'Outstanding') }}</span></td></tr>@empty<tr><td colspan="7" class="empty">No invoices match this view.</td></tr>@endforelse</tbody></table></div><div class="pager"><span>Page {{ $invoices->currentPage() }} of {{ $invoices->lastPage() }}</span><div>@if($invoices->previousPageUrl())<a href="{{ $invoices->previousPageUrl() }}">Previous</a>@endif @if($invoices->nextPageUrl())<a href="{{ $invoices->nextPageUrl() }}">Next</a>@endif</div></div></section>
<section class="section"><div class="section-head"><div><h3>Recent payments & receipts</h3><p>Latest recorded member and application payments.</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Received</th><th>Transaction reference</th><th>Method</th><th>Amount</th><th>Receipt</th></tr></thead><tbody>@forelse($payments as $payment)<tr><td>{{ ($payment->received_at ?? $payment->created_at)?->format('d M Y H:i') }}</td><td class="mono">{{ $payment->tx_reference }}</td><td>{{ ucwords(str_replace('_',' ',$payment->payment_method ?? 'payment')) }}</td><td class="money">UGX {{ number_format((float)$payment->amount,0) }}</td><td>@if($payment->receipt)<a class="btn btn-soft" href="{{ route('staff.receipts.show',$payment->receipt) }}">{{ $payment->receipt->receipt_number }}</a>@else—@endif</td></tr>@empty<tr><td colspan="5" class="empty">No payments recorded.</td></tr>@endforelse</tbody></table></div></section>
@endsection
