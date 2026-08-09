<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate Member Portal · ICGU</title>
    <link rel="stylesheet" href="{{ asset('css/member-portal.css') }}">
</head>
<body>
<div class="login-page">
    <section class="login-visual">
        <div class="brand"><span class="brand-mark">ICGU</span><span><strong>Institute of Corporate<br>Governance Uganda</strong><small>Secure activation</small></span></div>
        <div><span class="eyebrow">Invitation only</span><h1>Activate your secure ICGU Member Portal.</h1><p>Create your portal credentials to access membership status, renewals, billing and digital membership credentials.</p></div>
        <small>Invitation links are time-limited and may only be used once.</small>
    </section>
    <main class="login-panel">
        <div class="login-box">
            <span class="eyebrow">Account activation</span><h2>Set up your access</h2><p>Use your name and a strong password of at least 10 characters.</p>
            @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('member.invitation.accept', $token) }}">
                @csrf
                <div class="field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name"></div>
                <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" minlength="10" required autocomplete="new-password"></div>
                <div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" minlength="10" required autocomplete="new-password"></div>
                <button class="btn btn-accent" type="submit">Activate Member Portal</button>
            </form>
            <div class="login-foot">Already activated? <a href="{{ route('member.login') }}"><strong>Sign in here.</strong></a></div>
        </div>
    </main>
</div>
</body>
</html>
