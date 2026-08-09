<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Member Portal') · ICGU</title>
    <link rel="stylesheet" href="{{ asset('css/member-portal.css') }}">
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Member portal navigation">
        <a class="brand" href="{{ route('member.dashboard') }}">
            <span class="brand-mark">ICGU</span>
            <span><strong>Member Portal</strong><small>Institute of Corporate Governance Uganda</small></span>
        </a>
        <nav class="nav">
            <a class="{{ request()->routeIs('member.dashboard') ? 'active' : '' }}" href="{{ route('member.dashboard') }}">Overview</a>
            @php($primaryAccount = auth()->user()?->portalAccounts()->with('member')->orderByDesc('is_primary')->first())
            @if($primaryAccount)
                <a class="{{ request()->routeIs('member.membership') ? 'active' : '' }}" href="{{ route('member.membership', $primaryAccount->member) }}">My membership</a>
                <a class="{{ request()->routeIs('member.billing') ? 'active' : '' }}" href="{{ route('member.billing', $primaryAccount->member) }}">Billing & renewals</a>
            @endif
            <a href="https://icgu.org/training-activities/" target="_blank" rel="noopener">Training & development</a>
            <a href="https://icgu.org/" target="_blank" rel="noopener">ICGU website</a>
        </nav>
        <div class="sidebar-footer">
            <strong>ICGU Secretariat</strong><br>
            icgu@icgu.org<br>
            +256 414 250239/7
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div>
                <span class="eyebrow">ICGU</span>
                <h1>@yield('page-title', 'Member Portal')</h1>
            </div>
            <div class="userbox">
                <span class="avatar">{{ strtoupper(substr(auth()->user()?->name ?? 'M', 0, 1)) }}</span>
                <span class="user-name"><strong>{{ auth()->user()?->name }}</strong></span>
                <form method="POST" action="{{ route('member.logout') }}">
                    @csrf
                    <button class="btn btn-outline" type="submit">Sign out</button>
                </form>
            </div>
        </header>

        <div class="content">
            @if(session('status'))
                <div class="notice success" role="status">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="notice error" role="alert">
                    <strong>Please check the information below.</strong>
                    <ul>
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
