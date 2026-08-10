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
            <p>ICGU staff can sign in with their work Google account. Members can continue using their portal email and password.</p>
        </div>
        <small>ICGU · Plot 5 Katego Road, Kamwokya, Kampala · icgu@icgu.org</small>
    </section>
    <main class="login-panel">
        <div class="login-box">
            <span class="eyebrow">Secure access</span>
            <h2>Sign in to ICGU</h2>
            @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif

            <a class="btn btn-primary" href="{{ route('staff.google.redirect') }}" style="display:block;text-align:center;text-decoration:none;margin-bottom:18px">Continue with Google</a>
            <div style="text-align:center;font-size:12px;margin:-6px 0 22px;color:#667085">ICGU Secretariat & staff</div>

            <div style="border-top:1px solid #e5e7eb;padding-top:20px">
                <p><strong>Member sign in</strong></p>
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
                    <button class="btn btn-primary" type="submit">Sign in as member</button>
                </form>
            </div>
            <div class="login-foot">Staff access is limited to authorised ICGU Google Workspace accounts already registered in the system. For access assistance, contact <strong>icgu@icgu.org</strong>.</div>
        </div>
    </main>
</div>
</body>
</html>
