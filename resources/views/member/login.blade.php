<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ICGU Sign In</title>
    <link rel="stylesheet" href="{{ asset('css/member-portal.css') }}">
</head>
<body>
<div class="login-page">
    <section class="login-visual">
        <div class="brand">
            <span class="brand-mark">ICGU</span>
            <span><strong>Institute of Corporate<br>Governance Uganda</strong><small>Secure Portal</small></span>
        </div>
        <div>
            <span class="eyebrow">Good governance. Stronger institutions.</span>
            <h1>One secure sign-in for ICGU members and staff.</h1>
            <p>Use your ICGU account below. Members are taken to the membership portal, while authorised Secretariat and finance staff are automatically routed to the staff portal and MFA.</p>
        </div>
        <small>ICGU · Plot 5 Katego Road, Kamwokya, Kampala · icgu@icgu.org</small>
    </section>
    <main class="login-panel">
        <div class="login-box">
            <span class="eyebrow">Secure access</span>
            <h2>Sign in to ICGU</h2>
            <p>Members and authorised staff use the same sign-in form.</p>
            @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('member.login.submit') }}">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Sign in</button>
            </form>
            <div class="login-foot">Your account type is detected automatically. Staff accounts are protected by multi-factor authentication. For access assistance, contact <strong>icgu@icgu.org</strong>.</div>
        </div>
    </main>
</div>
</body>
</html>
