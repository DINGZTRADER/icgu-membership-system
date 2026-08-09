@extends('layouts.staff-admin')
@section('title','Reports')
@section('page-title','Executive Reports')
@section('content')
<div class="hero"><div><span class="eyebrow">Management information</span><h2>Membership performance & financial position</h2><p>Executive readout of membership composition, application throughput, renewals, collections and outstanding balances.</p></div></div>
<div class="grid grid-4 metrics"><div class="card metric"><small>Total members</small><strong>{{ number_format($report['members_total']) }}</strong></div><div class="card metric"><small>Lifetime invoice value</small><strong style="font-size:21px">UGX {{ number_format($report['invoice_value'],0) }}</strong></div><div class="card metric"><small>Outstanding</small><strong style="font-size:21px">UGX {{ number_format($report['outstanding_value'],0) }}</strong></div><div class="card metric"><small>Collections this year</small><strong style="font-size:21px">UGX {{ number_format($report['collections_year'],0) }}</strong></div></div>
<div class="grid grid-2 section">
@foreach([['Member status',$report['member_statuses']],['Membership categories',$report['member_plans']],['Application pipeline',$report['application_statuses']],['Renewal lifecycle',$report['renewal_statuses']]] as [$title,$series])
<section class="card"><div class="section-head"><h3>{{ $title }}</h3></div>@php($max=max(1,(int)$series->max()))<div class="kpi-list">@forelse($series as $label=>$value)<div class="kpi-row"><span>{{ ucwords(str_replace('_',' ',$label)) }}</span><div class="bar"><span style="width:{{ min(100,($value/$max)*100) }}%"></span></div><strong>{{ $value }}</strong></div>@empty<div class="empty">No data.</div>@endforelse</div></section>
@endforeach
</div>
<section class="section card"><div class="section-head"><div><h3>Monthly collections · {{ now()->year }}</h3><p>Gross payment entries recorded in the financial ledger.</p></div></div>@php($maxMonthly=max(1,(float)$report['monthly_collections']->max()))<div class="kpi-list">@forelse($report['monthly_collections'] as $month=>$amount)<div class="kpi-row"><span>{{ $month }}</span><div class="bar"><span style="width:{{ min(100,($amount/$maxMonthly)*100) }}%"></span></div><strong style="font-size:11px">{{ number_format((float)$amount/1000,0) }}k</strong></div>@empty<div class="empty">No collections recorded this year.</div>@endforelse</div></section>
@endsection
