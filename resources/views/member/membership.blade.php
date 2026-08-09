@extends('layouts.member-portal')
@section('title','Membership')
@section('page-title','Membership')
@section('content')
<div class="hero">
    <div><span class="eyebrow">{{ $member->membershipPlan?->name ?? 'ICGU Membership' }}</span><h2>{{ $member->display_name }}</h2><p>{{ $member->registration_number }} · Portal access: {{ ucfirst($account->access_role) }}</p></div>
    <span class="status {{ $member->status?->code === 'ACTIVE' ? 'ok' : 'bad' }}">{{ $member->status?->code ?? 'Unknown' }}</span>
</div>

<div class="grid grid-2">
    <section class="card">
        <div class="section-head"><h3>Membership standing</h3></div>
        <div class="meta">
            <div><span>Membership number</span><strong>{{ $member->registration_number }}</strong></div>
            <div><span>Category</span><strong>{{ $member->membershipPlan?->name ?? '—' }}</strong></div>
            <div><span>Current period</span><strong>{{ $member->currentPeriod ? $member->currentPeriod->start_date->format('d M Y').' – '.$member->currentPeriod->end_date->format('d M Y') : 'No current paid period' }}</strong></div>
            <div><span>Outstanding balance</span><strong>UGX {{ number_format((float)$member->outstanding_balance,0) }}</strong></div>
        </div>
        <div class="actions"><a class="btn btn-soft" href="{{ route('member.billing',$member) }}">View billing & renewals</a></div>
    </section>

    <section class="card">
        <div class="section-head"><h3>Digital credential</h3></div>
        @if($member->activeCredential)
            <iframe class="credential-frame {{ $member->activeCredential->credential_type === 'certificate' ? 'certificate-frame' : '' }}" title="ICGU digital membership credential" src="{{ url('/member/portal/members/'.$member->id.'/credential.svg') }}"></iframe>
            <div class="actions">
                <a class="btn btn-primary" target="_blank" href="{{ url('/member/portal/members/'.$member->id.'/credential.svg') }}">Open credential</a>
                <a class="btn btn-outline" target="_blank" href="{{ route('membership.verify.page',$member->activeCredential->verification_code) }}">Verify publicly</a>
            </div>
        @elseif($member->status?->code === 'ACTIVE' && in_array($account->access_role,['owner','representative'],true))
            <div class="empty"><p>Your current digital credential has not yet been issued.</p><form method="POST" action="{{ route('member.credential.issue',$member) }}">@csrf<button class="btn btn-accent" type="submit">Issue my digital credential</button></form></div>
        @else
            <div class="empty"><p>No current credential is available for this membership.</p></div>
        @endif
    </section>
</div>

@if(in_array($account->access_role,['owner','representative'],true))
<section class="section card">
    <div class="section-head"><div><h3>Profile details</h3><p style="margin:4px 0 0;color:#687487">Update the member details that are available for self-service.</p></div></div>
    <form method="POST" action="{{ route('member.profile.update',$member) }}">
        @csrf @method('PATCH')
        <div class="form-grid">
            <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="{{ old('phone',$member->phone) }}" autocomplete="tel"></div>
            @if($member->type === 'individual')
                <div class="field"><label for="job_title">Job title</label><input id="job_title" name="job_title" value="{{ old('job_title',$member->job_title) }}"></div>
                <div class="field"><label for="organization">Organisation</label><input id="organization" name="organization" value="{{ old('organization',$member->organization) }}"></div>
            @else
                <div class="field"><label>Organisation</label><input value="{{ $member->display_name }}" disabled><div class="help">Corporate legal-name changes require Secretariat review.</div></div>
            @endif
        </div>
        <div class="actions"><button class="btn btn-primary" type="submit">Save profile changes</button></div>
    </form>
</section>
@endif
@endsection
