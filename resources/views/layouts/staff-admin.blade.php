<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title','Staff') · ICGU Secretariat</title>
    <link rel="stylesheet" href="{{ asset('css/staff-admin.css') }}">
</head>
<body>
@php
    $staff = auth()->user();
    $staff->loadMissing('roles.permissions');
    $staffRoleNames = $staff->roles->pluck('name')->join(' · ');
@endphp
<div class="shell">
    <aside class="sidebar">
        <div class="brand"><div class="brand-mark">ICGU</div><div><strong>Secretariat Portal</strong><small>Institute of Corporate Governance Uganda</small></div></div>
        <nav class="nav" aria-label="Staff navigation">
            <a class="{{ request()->routeIs('staff.dashboard') ? 'active' : '' }}" href="{{ route('staff.dashboard') }}"><span>Executive dashboard</span></a>
            @if($staff->hasPermission('applications.view'))<a class="{{ request()->routeIs('staff.applications.*') ? 'active' : '' }}" href="{{ route('staff.applications.index') }}"><span>Applications</span></a>@endif
            @if($staff->hasPermission('members.view'))<a class="{{ request()->routeIs('staff.members.*') ? 'active' : '' }}" href="{{ route('staff.members.index') }}"><span>Members</span></a>@endif
            @if($staff->hasPermission('renewals.view'))<a class="{{ request()->routeIs('staff.renewals*') ? 'active' : '' }}" href="{{ route('staff.renewals') }}"><span>Renewals & arrears</span></a>@endif
            @if($staff->hasPermission('finance.view'))<a class="{{ request()->routeIs('staff.finance*') || request()->routeIs('staff.receipts.*') ? 'active' : '' }}" href="{{ route('staff.finance') }}"><span>Finance & receipts</span></a>@endif
            @if($staff->hasPermission('organisations.view'))<a class="{{ request()->routeIs('staff.organisations*') ? 'active' : '' }}" href="{{ route('staff.organisations') }}"><span>Organisations</span></a>@endif
            @if($staff->hasPermission('reports.view'))<a class="{{ request()->routeIs('staff.reports') ? 'active' : '' }}" href="{{ route('staff.reports') }}"><span>Reports</span></a>@endif
            @if($staff->hasPermission('audit.view'))<a class="{{ request()->routeIs('staff.audit') ? 'active' : '' }}" href="{{ route('staff.audit') }}"><span>Audit trail</span></a>@endif
        </nav>
        <div class="sidebar-foot">Accountability · Transparency · Integrity · Responsibility · Excellence</div>
    </aside>
    <main class="main">
        <header class="topbar">
            <h1>@yield('page-title','Secretariat')</h1>
            <div class="userbox">
                <div class="avatar">{{ strtoupper(substr($staff->name,0,1)) }}</div>
                <div class="user-meta"><strong>{{ $staff->name }}</strong><small>{{ $staffRoleNames }}</small></div>
                <form method="POST" action="{{ route('staff.logout') }}">@csrf<button class="btn btn-soft" type="submit">Sign out</button></form>
            </div>
        </header>
        <div class="content">
            @if(session('success'))<div class="notice success">{{ session('success') }}</div>@endif
            @if(session('activation_url'))<div class="notice info"><strong>Activation link:</strong> <span class="mono">{{ session('activation_url') }}</span></div>@endif
            @if($errors->any())<div class="notice error"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
