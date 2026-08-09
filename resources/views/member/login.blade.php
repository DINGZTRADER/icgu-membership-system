<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Member Login · ICGU</title>
    <link rel="stylesheet" href="{{ asset('css/member-portal.css') }}">
</head>
<body>
<div class="login-page">
    <section class="login-visual">
        <div class="brand">
            <span class="brand-mark">ICGU</span>
            <span><strong>Institute of Corporate<br>Governance Uganda</strong><small>Member Portal</small></span>
        </div>
        <div>
            <span class="eyebrow">Good governance. Stronger institutions.</span>
            <h1>Your membership, credentials and renewals in one secure place.</h1>
            <p>Access your current membership status, billing records, renewal information and verifiable ICGU digital credential.</p>
        </div>
        <small>ICGU · Plot 5 Katego Road, Kamwokya, Kampala · icgu@icgu.org</small>
    </section>
    <main class="login-panel">
        <div class="login-box">
            <span class="eyebrow">Member access</span>
            <h2>Welcome back</h2>
            <p>Sign in using the account linked to your ICGU membership.</p>
            @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('member.login.submit') }}">
                @csrf
                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button class="btn btn-primary" type="submit">Sign in to Member Portal</button>
            </form>
            <div class="login-foot">Portal access is issued by the ICGU Secretariat. If you are a member without access, contact <strong>icgu@icgu.org</strong>.</div>
        </div>
    </main>
</div>
</body>
</html>
