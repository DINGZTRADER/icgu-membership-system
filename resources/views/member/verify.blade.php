<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Membership · ICGU</title>
    <link rel="stylesheet" href="{{ asset('css/member-portal.css') }}">
</head>
<body>
<div class="public-page">
    <div class="brand" style="max-width:680px;margin:0 auto;color:#12315a"><span class="brand-mark" style="color:#fff">ICGU</span><span><strong>Institute of Corporate Governance Uganda</strong><small style="color:#687487">Official membership verification</small></span></div>
    <main class="verify-card">
        <div class="verify-icon {{ $valid ? '' : 'bad' }}">{{ $valid ? '✓' : '!' }}</div>
        <span class="eyebrow">Credential verification</span>
        <h1>{{ $valid ? 'Membership is current' : 'Credential is not current' }}</h1>
        <p style="color:#687487">This page verifies an ICGU digital {{ $credential->credential_type }} against the Institute's membership records.</p>
        <div class="verify-list">
            <div><span>Member</span><strong>{{ $credential->member->display_name }}</strong></div>
            <div><span>Membership number</span><strong>{{ $credential->member->registration_number }}</strong></div>
            <div><span>Category</span><strong>{{ $credential->member->membershipPlan?->name ?? '—' }}</strong></div>
            <div><span>Status</span><strong>{{ $credential->member->status?->code ?? '—' }}</strong></div>
            <div><span>Credential</span><strong>{{ ucfirst($credential->credential_type) }}</strong></div>
            <div><span>Valid until</span><strong>{{ $credential->valid_until?->format('d M Y') }}</strong></div>
        </div>
        <p class="help" style="margin-top:22px">Verification code: {{ $credential->verification_code }}</p>
        <div class="actions" style="justify-content:center"><a class="btn btn-primary" href="https://icgu.org/">Visit ICGU</a></div>
    </main>
</div>
</body>
</html>
