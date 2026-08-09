<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Secure Your Account · ICGU</title><link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}"></head>
<body class="login-page">
<div class="login-card" style="max-width:620px">
    <div class="brand-mark">ICGU</div>
    <span class="eyebrow">Secretariat security</span>
    <h1>Set up multi-factor authentication</h1>
    <p>Use Microsoft Authenticator, Google Authenticator, 1Password, Authy, or another TOTP-compatible authenticator.</p>
    @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
    <div class="notice"><strong>Manual setup key</strong><br><code style="word-break:break-all">{{ $secret }}</code></div>
    <p style="font-size:13px">Authenticator URI: <code style="word-break:break-all">{{ $provisioningUri }}</code></p>
    <form method="POST" action="{{ route('staff.mfa.confirm') }}">@csrf
        <div class="field"><label for="code">6-digit authenticator code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required autofocus></div>
        <button class="btn btn-primary" type="submit">Verify and enable MFA</button>
    </form>
    <p style="font-size:12px;margin-top:20px">The secret is encrypted in the ICGU database and is never shown again after setup.</p>
</div>
</body>
</html>
