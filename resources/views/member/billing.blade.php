@extends('layouts.member-portal')
@section('title','Billing & Renewals')
@section('page-title','Billing & Renewals')
@section('content')
<div class="hero">
    <div><span class="eyebrow">{{ $member->registration_number }}</span><h2>Billing & annual renewal</h2><p>Review invoices, payments, receipts and annual membership renewals for {{ $member->display_name }}.</p></div>
    <div><span class="status {{ (float)$member->outstanding_balance > 0 ? 'warn' : 'ok' }}">UGX {{ number_format((float)$member->outstanding_balance,0) }} outstanding</span></div>
</div>

<div class="grid grid-4">
    <div class="card metric"><small>Membership status</small><strong style="font-size:22px">{{ $member->status?->code ?? '—' }}</strong></div>
    <div class="card metric"><small>Annual renewal fee</small><strong style="font-size:22px">UGX {{ number_format((float)($member->membershipPlan?->renewal_fee ?? 0),0) }}</strong></div>
    <div class="card metric"><small>Total invoices</small><strong>{{ $member->invoices->count() }}</strong></div>
    <div class="card metric"><small>Payments recorded</small><strong>{{ $member->payments->count() }}</strong></div>
</div>

<section class="section card">
    <div class="section-head">
        <div><h3>Annual renewal</h3><p style="margin:4px 0 0;color:#687487">Generate the next annual renewal invoice when you are ready to renew.</p></div>
        <form method="POST" action="{{ route('member.renew',$member) }}">@csrf<button class="btn btn-accent" type="submit">Prepare renewal invoice</button></form>
    </div>
    @if($member->renewals->isEmpty())
        <div class="empty">No annual renewal cycle has been generated yet.</div>
    @else
        <div class="table-wrap"><table class="table"><thead><tr><th>Year</th><th>Status</th><th>Invoice</th><th>Fee</th><th>Balance</th><th>Coverage</th></tr></thead><tbody>
        @foreach($member->renewals as $renewal)
            <tr><td>{{ $renewal->target_year }}</td><td><span class="status {{ $renewal->status === 'renewed' ? 'ok' : 'warn' }}">{{ strtoupper($renewal->status) }}</span></td><td>{{ $renewal->invoice?->invoice_number ?? '—' }}</td><td class="money">UGX {{ number_format((float)$renewal->renewal_fee,0) }}</td><td class="money">UGX {{ number_format((float)$renewal->balance_due,0) }}</td><td>{{ $renewal->resultingPeriod ? $renewal->resultingPeriod->start_date->format('d M Y').' – '.$renewal->resultingPeriod->end_date->format('d M Y') : 'Pending settlement' }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</section>

<section class="section">
    <div class="section-head"><h3>Invoices</h3></div>
    <div class="card" style="padding:0">
        @if($member->invoices->isEmpty())<div class="empty">No invoices are recorded for this membership.</div>@else
        <div class="table-wrap" style="border:0"><table class="table"><thead><tr><th>Invoice</th><th>Date</th><th>Fee type</th><th>Amount</th><th>Balance</th><th>Status</th></tr></thead><tbody>
        @foreach($member->invoices as $invoice)
            <tr><td><strong>{{ $invoice->invoice_number ?? '—' }}</strong></td><td>{{ $invoice->created_at?->format('d M Y') }}</td><td>{{ ucwords(str_replace('_',' ',$invoice->fee_type ?? 'membership')) }}</td><td class="money">UGX {{ number_format((float)$invoice->amount,0) }}</td><td class="money">UGX {{ number_format((float)$invoice->balance_due,0) }}</td><td><span class="status {{ $invoice->is_fully_settled ? 'ok' : ($invoice->is_overdue ? 'bad' : 'warn') }}">{{ $invoice->is_fully_settled ? 'PAID' : ($invoice->is_overdue ? 'OVERDUE' : 'OUTSTANDING') }}</span></td></tr>
        @endforeach
        </tbody></table></div>@endif
    </div>
</section>

<section class="section">
    <div class="section-head"><h3>Payments & receipts</h3></div>
    <div class="card" style="padding:0">
        @if($member->payments->isEmpty())<div class="empty">No payments are recorded for this membership.</div>@else
        <div class="table-wrap" style="border:0"><table class="table"><thead><tr><th>Date</th><th>Reference</th><th>Method</th><th>Amount</th><th>Receipt</th></tr></thead><tbody>
        @foreach($member->payments as $payment)
            <tr><td>{{ ($payment->received_at ?? $payment->created_at)?->format('d M Y') }}</td><td>{{ $payment->reference ?? $payment->external_reference ?? '—' }}</td><td>{{ ucwords(str_replace('_',' ',$payment->payment_method ?? 'payment')) }}</td><td class="money">UGX {{ number_format((float)$payment->amount,0) }}</td><td>{{ $payment->receipt?->receipt_number ?? '—' }}</td></tr>
        @endforeach
        </tbody></table></div>@endif
    </div>
</section>
@endsection
