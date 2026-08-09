<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Secretariat Sign In · ICGU</title><link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}"></head>
<body class="login-page">
<div class="login-card">
    <div class="brand-mark">ICGU</div>
    <span class="eyebrow">Secretariat & Executive Portal</span>
    <h1>Staff sign in</h1>
    <p>Operational access for authorised ICGU Secretariat, finance, audit and executive users.</p>
    @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('staff.login.submit') }}">
        @csrf
        <div class="field"><label for="email">Work email</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></div>
        <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary" type="submit">Sign in to Secretariat Portal</button>
    </form>
    <p style="font-size:12px;margin-top:20px">Access is permission-controlled and all material membership and financial actions are audit logged.</p>
</div>
</body>
</html>
