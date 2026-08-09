@extends('layouts.member-portal')
@section('title','Dashboard')
@section('page-title','Member Dashboard')
@section('content')
<div class="hero">
    <div><span class="eyebrow">Welcome, {{ auth()->user()->name }}</span><h2>Your ICGU membership at a glance</h2><p>Review status, validity, outstanding balances and the actions that need your attention.</p></div>
</div>

@if($accounts->isEmpty())
    <div class="card empty"><h3>No membership linked</h3><p>Please contact the ICGU Secretariat to link your membership to this account.</p></div>
@else
    @php
        $activeCount = $accounts->filter(fn($a) => $a->member->status?->code === 'ACTIVE')->count();
        $balance = $accounts->sum(fn($a) => (float) $a->member->outstanding_balance);
        $nextExpiry = $accounts->map(fn($a) => $a->member->currentPeriod?->end_date)->filter()->sort()->first();
        $credentials = $accounts->filter(fn($a) => $a->member->activeCredential !== null)->count();
    @endphp
    <div class="grid grid-4">
        <div class="card metric"><small>Linked memberships</small><strong>{{ $accounts->count() }}</strong></div>
        <div class="card metric"><small>Active memberships</small><strong>{{ $activeCount }}</strong></div>
        <div class="card metric"><small>Outstanding balance</small><strong>UGX {{ number_format($balance,0) }}</strong></div>
        <div class="card metric"><small>Digital credentials</small><strong>{{ $credentials }}</strong></div>
    </div>

    <section class="section">
        <div class="section-head"><h3>Your memberships</h3>@if($nextExpiry)<span class="status warn">Next expiry {{ $nextExpiry->format('d M Y') }}</span>@endif</div>
        <div class="grid grid-2">
            @foreach($accounts as $account)
                @php($member = $account->member)
                <article class="card membership-card">
                    <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start">
                        <div><span class="eyebrow">{{ $member->membershipPlan?->name ?? 'ICGU Membership' }}</span><h3 style="margin:6px 0 2px;font:700 23px Georgia,serif;color:#0b2342">{{ $member->display_name }}</h3><small style="color:#687487">{{ $member->registration_number }}</small></div>
                        <span class="status {{ $member->status?->code === 'ACTIVE' ? 'ok' : 'bad' }}">{{ $member->status?->code ?? 'Unknown' }}</span>
                    </div>
                    <div class="meta">
                        <div><span>Valid until</span><strong>{{ $member->currentPeriod?->end_date?->format('d M Y') ?? $member->latestPeriod?->end_date?->format('d M Y') ?? '—' }}</strong></div>
                        <div><span>Outstanding</span><strong>UGX {{ number_format((float)$member->outstanding_balance,0) }}</strong></div>
                        <div><span>Portal role</span><strong>{{ ucfirst($account->access_role) }}</strong></div>
                        <div><span>Credential</span><strong>{{ $member->activeCredential ? ucfirst($member->activeCredential->credential_type) : 'Not issued' }}</strong></div>
                    </div>
                    <div class="actions">
                        <a class="btn btn-primary" href="{{ route('member.membership',$member) }}">View membership</a>
                        <a class="btn btn-soft" href="{{ route('member.billing',$member) }}">Billing & renewals</a>
                    </div>
                </article>
            @endforeach
        </div>
    </section>
@endif

<section class="section">
    <div class="section-head"><h3>Member opportunities</h3></div>
    <div class="grid grid-4">
        <div class="card"><span class="eyebrow">Training</span><h3>Governance development</h3><p style="color:#687487">Explore ICGU board, management and corporate governance training programmes.</p><a class="btn btn-soft" target="_blank" rel="noopener" href="https://icgu.org/training-activities/">Explore training</a></div>
        <div class="card"><span class="eyebrow">Network</span><h3>Professional community</h3><p style="color:#687487">Stay connected with practising professionals and governance leaders.</p></div>
        <div class="card"><span class="eyebrow">Standing</span><h3>Verifiable membership</h3><p style="color:#687487">Use your digital card or certificate to demonstrate current membership standing.</p></div>
        <div class="card"><span class="eyebrow">Support</span><h3>Secretariat assistance</h3><p style="color:#687487">Need help with your record? Contact icgu@icgu.org or +256 414 250239/7.</p></div>
    </div>
</section>
@endsection
