<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MFA Verification · ICGU</title><link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}"></head>
<body class="login-page">
<div class="login-card">
    <div class="brand-mark">ICGU</div>
    <span class="eyebrow">Secretariat security</span>
    <h1>Verify your sign-in</h1>
    <p>Enter the 6-digit code from your authenticator. You may also use one unused recovery code.</p>
    @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('staff.mfa.challenge.submit') }}">@csrf
        <div class="field"><label for="code">Authenticator or recovery code</label><input id="code" name="code" autocomplete="one-time-code" maxlength="32" required autofocus></div>
        <button class="btn btn-primary" type="submit">Verify and continue</button>
    </form>
    <p style="font-size:12px;margin-top:20px">MFA verification is required for ICGU Secretariat access.</p>
</div>
</body>
</html>
