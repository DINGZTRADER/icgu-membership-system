@extends('layouts.staff-admin')
@section('title','Import Members')
@section('page-title','Import Members')
@section('content')
<div class="section-head"><div><h3>Bulk member import</h3><p>Upload the approved ICGU CSV template. Validate first, correct any errors, then import the clean file.</p></div><div class="actions"><a class="btn btn-soft" href="{{ route('staff.members.import.template') }}">Download CSV template</a><a class="btn btn-soft" href="{{ route('staff.members.index') }}">Back to Members</a></div></div>
@if($errors->any())<div class="notice error"><strong>Import could not continue.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<div class="grid grid-2">
<section class="card">
<div class="section-head"><h3>Upload CSV</h3></div>
<form method="POST" action="{{ route('staff.members.import.submit') }}" enctype="multipart/form-data">@csrf
<div class="field"><label>Member CSV</label><input type="file" name="member_csv" accept=".csv,text/csv" required><small>Maximum 10 MB. Up to {{ number_format((int)config('production.pilot_import_max_rows',5000)) }} member rows per file.</small></div>
<div class="actions"><button class="btn btn-primary" type="submit" name="action" value="validate">Validate only</button><button class="btn btn-accent" type="submit" name="action" value="commit">Validate & import</button></div>
<p><small><strong>Recommended:</strong> use Validate only first. Nothing is added to the member register unless the entire file passes validation and you choose Validate & import.</small></p>
</form>
</section>
<section class="card">
<div class="section-head"><h3>Important file rules</h3></div>
<ul>
<li>The first row must contain the exact column names shown below, in the same order.</li>
<li>Registration numbers must use <strong>ICGU/NNN/YYYY</strong>.</li>
<li>Dates must use <strong>YYYY-MM-DD</strong>.</li>
<li>ACTIVE members must include period_start, period_end and target_year.</li>
<li>Duplicate registration numbers or emails are blocked before import.</li>
<li>Job seeker accepts yes/no, true/false or 1/0.</li>
<li>Profile photos and CV files are uploaded from the individual member record after a bulk import; binary files are not embedded in CSV.</li>
</ul>
</section>
</div>
<section class="section card"><div class="section-head"><div><h3>Required CSV structure</h3><p>Admins can download the template above or create a CSV with these exact columns.</p></div></div>
<div class="table-wrap"><table class="table"><thead><tr><th>Column</th><th>What to enter</th></tr></thead><tbody>
@php($help=[
'registration_number'=>'ICGU/NNN/YYYY, e.g. ICGU/001/2026',
'type'=>'individual or corporate',
'plan_code'=>'Membership plan code shown below',
'status'=>'ACTIVE, PENDING, EXPIRED, SUSPENDED, RESIGNED or ARCHIVED',
'first_name'=>'Required for individual members',
'last_name'=>'Required for individual members',
'company_name'=>'Required for corporate members',
'email'=>'Unique primary member email',
'phone'=>'Telephone number; may be blank',
'organization'=>'Employer / organisation; may be blank',
'job_title'=>'Current job title; may be blank',
'membership_tier'=>'ICGU tier/level; may be blank if not yet assigned',
'is_job_seeker'=>'yes/no, true/false or 1/0',
'registration_date'=>'YYYY-MM-DD',
'period_start'=>'YYYY-MM-DD; required for ACTIVE',
'period_end'=>'YYYY-MM-DD; required for ACTIVE',
'target_year'=>'Membership year, e.g. 2026',
])
@foreach($headers as $header)<tr><td><strong class="mono">{{ $header }}</strong></td><td>{{ $help[$header] ?? '—' }}</td></tr>@endforeach
</tbody></table></div></section>
<div class="grid grid-2 section">
<section class="card"><h3>Plan codes</h3><div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Membership</th><th>Audience</th></tr></thead><tbody>@foreach($plans as $plan)<tr><td class="mono">{{ $plan->code }}</td><td>{{ $plan->name }}</td><td>{{ ucfirst($plan->audience) }}</td></tr>@endforeach</tbody></table></div></section>
<section class="card"><h3>Status codes</h3><div class="table-wrap"><table class="table"><thead><tr><th>Code</th><th>Status</th></tr></thead><tbody>@foreach($statuses as $status)<tr><td class="mono">{{ $status->code }}</td><td>{{ $status->label }}</td></tr>@endforeach</tbody></table></div></section>
</div>
@if($batch)
<section class="section card"><div class="section-head"><div><h3>Validation result</h3><p>Batch {{ $batch->uuid }}</p></div><span class="status {{ $batch->conflict_rows+$batch->error_rows===0?'ok':'warn' }}">{{ strtoupper($batch->status) }}</span></div>
<div class="meta"><div><span>Rows</span><strong>{{ number_format($batch->total_rows) }}</strong></div><div><span>Valid</span><strong>{{ number_format($batch->valid_rows) }}</strong></div><div><span>Conflicts</span><strong>{{ number_format($batch->conflict_rows) }}</strong></div><div><span>Errors</span><strong>{{ number_format($batch->error_rows) }}</strong></div><div><span>Imported</span><strong>{{ number_format($batch->imported_rows) }}</strong></div></div>
@php($problemRows=$batch->rows->whereIn('disposition',['conflict','error'])->take(30))
@if($problemRows->isNotEmpty())<div class="notice error">No records from this file should be imported until these problems are corrected.</div><div class="table-wrap"><table class="table"><thead><tr><th>CSV row</th><th>Registration</th><th>Result</th><th>Issues</th></tr></thead><tbody>@foreach($problemRows as $row)<tr><td>{{ $row->row_number }}</td><td class="mono">{{ $row->registration_number ?? '—' }}</td><td>{{ $row->disposition }}</td><td>{{ implode(' ',(array)$row->issues) }}</td></tr>@endforeach</tbody></table></div>@else<div class="notice success">All {{ number_format($batch->valid_rows) }} rows passed validation. Re-upload the same file and choose <strong>Validate & import</strong> to commit it.</div>@endif
</section>
@endif
@endsection
