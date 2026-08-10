@extends('layouts.staff-admin')
@section('title','Add Member')
@section('page-title','Add Member')
@section('content')
<div class="section-head"><div><h3>Create a member record</h3><p>Add an existing or newly approved ICGU member directly to the register. Individual members require a real profile photo. Job seekers require a CV.</p></div><a class="btn btn-soft" href="{{ route('staff.members.index') }}">Back to Members</a></div>
@if($errors->any())<div class="notice error"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('staff.members.store') }}" enctype="multipart/form-data">@csrf
<div class="grid grid-2">
<section class="card"><div class="section-head"><h3>Identity</h3></div>
<div class="field"><label>Member type</label><select name="type" required><option value="individual" @selected(old('type','individual')==='individual')>Individual</option><option value="corporate" @selected(old('type')==='corporate')>Corporate</option></select></div>
<div class="field"><label>Registration number</label><input name="registration_number" value="{{ old('registration_number') }}" placeholder="Leave blank to generate automatically"><small>Existing records may use ICGU/NNN/YYYY. Leave blank for the next automatic number.</small></div>
<div class="grid grid-2"><div class="field"><label>Title</label><input name="title" value="{{ old('title') }}" placeholder="Mr, Ms, Dr..."></div><div class="field"><label>First name</label><input name="first_name" value="{{ old('first_name') }}"></div></div>
<div class="field"><label>Last name</label><input name="last_name" value="{{ old('last_name') }}"></div>
<div class="field"><label>Company name</label><input name="company_name" value="{{ old('company_name') }}" placeholder="Required for corporate members"></div>
<div class="field"><label>Real profile photo</label><input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp"><small>Required for individual members. JPG, PNG or WebP, maximum 5 MB.</small></div>
</section>
<section class="card"><div class="section-head"><h3>Membership</h3></div>
<div class="field"><label>Membership category</label><select name="membership_plan_id" required><option value="">Select category</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string)old('membership_plan_id')===(string)$plan->id)>{{ $plan->name }} ({{ $plan->code }})</option>@endforeach</select></div>
<div class="field"><label>Membership tier</label><input name="membership_tier" value="{{ old('membership_tier') }}" placeholder="Enter the ICGU tier used for this member"><small>This is separate from the membership category so ICGU can use its own tier terminology.</small></div>
<div class="field"><label>Status</label><select name="status_id" required><option value="">Select status</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)old('status_id')===(string)$status->id)>{{ $status->label }} ({{ $status->code }})</option>@endforeach</select></div>
<div class="field"><label>Registration date</label><input name="registration_date" type="date" value="{{ old('registration_date',today()->toDateString()) }}" required></div>
<div class="grid grid-2"><div class="field"><label>Period start</label><input name="period_start" type="date" value="{{ old('period_start') }}"></div><div class="field"><label>Period end</label><input name="period_end" type="date" value="{{ old('period_end') }}"></div></div>
<div class="field"><label>Target year</label><input name="target_year" type="number" min="1990" max="{{ now()->year+2 }}" value="{{ old('target_year',now()->year) }}"><small>Active members must have start date, end date and target year.</small></div>
</section>
<section class="card"><div class="section-head"><h3>Contact & career</h3></div>
<div class="field"><label>Email</label><input name="email" type="email" value="{{ old('email') }}" required></div>
<div class="field"><label>Phone</label><input name="phone" value="{{ old('phone') }}"></div>
<div class="field"><label>Organisation / employer</label><input name="organization" value="{{ old('organization') }}"></div>
<div class="field"><label>Job title</label><input name="job_title" value="{{ old('job_title') }}"></div>
<label style="display:flex;gap:10px;align-items:center;margin:16px 0"><input type="checkbox" name="is_job_seeker" value="1" @checked(old('is_job_seeker'))> <strong>Member is seeking employment / opportunities</strong></label>
<div class="field"><label>CV</label><input name="cv" type="file" accept=".pdf,.doc,.docx"><small>Required when Job Seeker is selected. PDF, DOC or DOCX, maximum 10 MB. Stored privately.</small></div>
</section>
<section class="card"><div class="section-head"><h3>Before saving</h3></div><p>The member record, primary email, membership period and status history are created together and logged in the audit trail.</p><p>Profile photos and CVs are stored in the private membership-document storage area and are only served through authorised application routes.</p><div class="actions"><button class="btn btn-primary" type="submit">Create member</button><a class="btn btn-soft" href="{{ route('staff.members.index') }}">Cancel</a></div></section>
</div>
</form>
@endsection
