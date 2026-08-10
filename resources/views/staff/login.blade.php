<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Secretariat Sign In · ICGU</title><link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}"></head>
<body class="login-page">
<div class="login-card">
    <div class="brand-mark">ICGU</div>
    <span class="eyebrow">Secretariat & Executive Portal</span>
    <h1>Staff sign in</h1>
    <p>Use your authorised ICGU Google Workspace account.</p>
    @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
    <a class="btn btn-primary" href="{{ route('staff.google.redirect') }}" style="display:block;text-align:center;text-decoration:none">Continue with Google</a>
    <p style="font-size:12px;margin-top:20px">Only active staff accounts already registered by ICGU can access the Secretariat portal.</p>
</div>
</body>
</html>
