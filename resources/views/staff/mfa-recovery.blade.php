<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recovery Codes · ICGU</title><link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}"></head>
<body class="login-page">
<div class="login-card" style="max-width:620px">
    <div class="brand-mark">ICGU</div>
    <span class="eyebrow">Secretariat security</span>
    <h1>Save your recovery codes</h1>
    <p>Store these codes in an approved password manager or another secure offline location. Each code works once and they will not be shown again.</p>
    <div class="card" style="margin:20px 0;text-align:left"><div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">@foreach($codes as $code)<code>{{ $code }}</code>@endforeach</div></div>
    <a class="btn btn-primary" href="{{ route('staff.dashboard') }}">I have saved these codes</a>
</div>
</body>
</html>
