@extends('layouts.staff-admin')
@section('title',$receipt->receipt_number)
@section('page-title','Payment Receipt')
@section('content')
<div class="receipt">
    <div class="receipt-head"><div><span class="eyebrow">Institute of Corporate Governance Uganda</span><h2 style="font:700 30px Georgia,serif;color:#082746;margin:8px 0">Official Receipt</h2><div class="mono">{{ $receipt->receipt_number }}</div></div><div style="text-align:right"><small>Issued</small><strong style="display:block">{{ $receipt->issued_at?->format('d M Y H:i') }}</strong></div></div>
    @php($payer=$receipt->member?->display_name ?? ($receipt->application?->organisation?->legal_name ?? trim(($receipt->application?->first_name ?? '').' '.($receipt->application?->last_name ?? '')) ?: $receipt->application?->email))
    <div class="meta" style="margin-top:18px"><div><span>Received from</span><strong>{{ $payer ?: '—' }}</strong></div><div><span>Membership / application</span><strong>{{ $receipt->member?->registration_number ?? $receipt->application?->reference ?? '—' }}</strong></div><div><span>Payment reference</span><strong class="mono">{{ $receipt->payment_reference ?? $receipt->payment?->tx_reference ?? '—' }}</strong></div><div><span>Payment method</span><strong>{{ ucwords(str_replace('_',' ',$receipt->payment?->payment_method ?? 'payment')) }}</strong></div></div>
    <div style="margin:34px 0;padding:24px;background:#f4f7fa;border-radius:12px"><small>Amount received</small><div class="receipt-total">{{ $receipt->currency }} {{ number_format((float)$receipt->amount,0) }}</div></div>
    <p style="color:#69778a;font-size:13px">This receipt records a payment in the ICGU membership financial ledger. Corrections are made through offsetting ledger entries rather than alteration of the original transaction.</p>
    <div class="actions no-print"><button class="btn btn-primary" type="button" onclick="window.print()">Print receipt</button><a class="btn btn-soft" href="{{ route('staff.finance') }}">Back to finance</a></div>
</div>
@endsection
