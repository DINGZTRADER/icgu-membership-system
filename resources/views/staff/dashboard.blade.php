@extends('layouts.staff-admin')
@section('title','Executive Dashboard')
@section('page-title','Executive Dashboard')
@section('content')
<div class="hero"><div><span class="eyebrow">Secretariat command centre</span><h2>Membership operations at a glance</h2><p>Monitor the membership domains authorised for your role from one operational workspace.</p></div><span class="status info">{{ $roles->join(' · ') }}</span></div>
<div class="grid grid-4 metrics">
    <div class="card metric"><small>Active members</small><strong>{{ number_format($stats['members_active']) }}</strong><div class="delta">{{ number_format($stats['members_total']) }} total records</div></div>
    <div class="card metric"><small>Expired members</small><strong>{{ $stats['members_expired'] }}</strong></div>
    <div class="card metric"><small>Corporate members</small><strong>{{ $stats['corporates'] }}</strong></div>
    @if(auth()->user()->hasPermission('applications.view'))<div class="card metric"><small>Open applications</small><strong>{{ number_format($stats['applications_open']) }}</strong><div class="delta">{{ $stats['applications_decision'] }} need a decision</div></div>@endif
    @if(auth()->user()->hasPermission('renewals.view'))<div class="card metric"><small>Open renewals</small><strong>{{ $stats['renewals_open'] }}</strong></div>@endif
    @if(auth()->user()->hasPermission('finance.view'))
        <div class="card metric"><small>Outstanding balance</small><strong style="font-size:22px">UGX {{ number_format($stats['outstanding_balance'],0) }}</strong><div class="delta">{{ $stats['overdue_invoices'] }} overdue invoices</div></div>
        <div class="card metric"><small>Collections this month</small><strong style="font-size:22px">UGX {{ number_format($stats['gross_collections_month'],0) }}</strong><div class="delta">Gross recorded payments</div></div>
    @endif
</div>

@if(auth()->user()->hasPermission('applications.view') || auth()->user()->hasPermission('renewals.view'))
<div class="detail-grid section">
@if(auth()->user()->hasPermission('applications.view'))
<section>
    <div class="section-head"><div><h3>Applications requiring attention</h3><p>Oldest open cases appear first.</p></div><a class="btn btn-soft" href="{{ route('staff.applications.index') }}">View all</a></div>
    <div class="table-wrap"><table class="table"><thead><tr><th>Reference</th><th>Applicant</th><th>Plan</th><th>Status</th>@if(auth()->user()->hasPermission('finance.view'))<th>Balance</th>@endif</tr></thead><tbody>
    @forelse($attentionApplications as $application)
        @php($name = $application->organisation?->legal_name ?: trim(($application->first_name ?? '').' '.($application->last_name ?? '')))
        <tr><td><a href="{{ route('staff.applications.show',$application->reference) }}"><strong class="mono">{{ $application->reference }}</strong></a></td><td>{{ $name ?: $application->email }}</td><td>{{ $application->plan?->name }}</td><td><span class="status {{ $application->status === 'approved_pending_payment' ? 'warn' : 'info' }}">{{ str_replace('_',' ',$application->status) }}</span></td>@if(auth()->user()->hasPermission('finance.view'))<td class="money">UGX {{ number_format((float)($application->invoice?->balance_due ?? 0),0) }}</td>@endif</tr>
    @empty<tr><td colspan="5" class="empty">No open applications require attention.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endif
@if(auth()->user()->hasPermission('renewals.view'))
<section>
    <div class="section-head"><div><h3>Memberships expiring</h3><p>Next 60 days.</p></div></div>
    <div class="card" style="padding:0"><table class="table"><tbody>
    @forelse($expiringMembers as $member)
        <tr><td><a href="{{ route('staff.members.show',$member) }}"><strong>{{ $member->display_name }}</strong></a><br><small>{{ $member->registration_number }}</small></td><td style="text-align:right">{{ $member->currentPeriod?->end_date?->format('d M Y') ?? '—' }}</td></tr>
    @empty<tr><td class="empty">No active memberships expire within 60 days.</td></tr>@endforelse
    </tbody></table></div>
</section>
@endif
</div>
@endif

@if(auth()->user()->hasPermission('audit.view'))
<section class="section"><div class="section-head"><div><h3>Recent audited activity</h3><p>Immutable system events.</p></div><a class="btn btn-soft" href="{{ route('staff.audit') }}">Open audit trail</a></div><div class="table-wrap"><table class="table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead><tbody>@forelse($recentAudit as $log)<tr><td>{{ $log->created_at?->format('d M Y H:i') }}</td><td>{{ $log->user?->name ?? 'System' }}</td><td><span class="status info">{{ str_replace('_',' ',$log->action) }}</span></td><td class="mono">{{ class_basename($log->entity) }}{{ $log->entity_id ? ' #'.$log->entity_id : '' }}</td></tr>@empty<tr><td colspan="4" class="empty">No audit entries yet.</td></tr>@endforelse</tbody></table></div></section>
@endif
@endsection
