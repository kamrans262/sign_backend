@extends('admin.layout')
@section('title',isset($record)?'Manage Work Acceptance':'Create Work Acceptance')
@section('breadcrumb','Work Acceptance Management')
@section('heading',isset($record)?'Manage Work Acceptance':'Create Work Acceptance')
@section('description','Review drafts, approved versions and signed customer evidence. Signed records are read-only.')
@section('content')
@php $editing=isset($record);$draft=!$editing||$record['status']==='draft'; @endphp
<section class="panel">
<div class="panel-header"><div><h2>{{ $editing?$record['number']:'New Acceptance Report' }}</h2><p class="panel-header-hint">{{ $editing?ucwords(str_replace('_',' ',$record['status'])):'Draft' }}</p></div><a class="btn btn-outline" href="{{ route('admin.acceptances.index') }}">All Acceptances</a></div>
<form style="padding:20px" class="form-grid" method="post" action="{{ $editing?route('admin.acceptances.update',$record['id']):route('admin.acceptances.store') }}">
@csrf @if($editing)@method('patch')<input type="hidden" name="revision" value="{{ $record['revision'] }}">@endif
@if(!$editing)<label>Company<select class="input" name="company_id" required><option value="">Select company</option>@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach</select></label>@endif
<label>Job<select class="input" name="job_id" required @disabled($editing||!$draft)><option value="">Select a real job</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected($editing&&$record['job_id']==$job->id)>{{ $job->reference_number }} · {{ $job->title }} ({{ $job->company?->name }})</option>@endforeach
</select></label>
<label>Completed Work<textarea class="input" name="completed_work" maxlength="16000" rows="5" required @readonly(!$draft)>{{ old('completed_work',$record['completed_work']??'') }}</textarea></label>
<label>Completion Date<input class="input" type="date" name="completion_date" value="{{ old('completion_date',$record['completion_date']??date('Y-m-d')) }}" required @readonly(!$draft)></label>
<label>Cleaning / Restoration<select class="input" name="cleaning_status" required @disabled(!$draft)>
@foreach(['cleaned'=>'Cleaned / Restored','pending'=>'Pending','not_applicable'=>'Not Applicable'] as $key=>$text)<option value="{{ $key }}" @selected(old('cleaning_status',$record['cleaning_status']??'cleaned')===$key)>{{ $text }}</option>@endforeach
</select></label>
<label>Outstanding Issues<textarea class="input" name="outstanding_issues" maxlength="12000" rows="3" @readonly(!$draft)>{{ old('outstanding_issues',$record['outstanding_issues']??'') }}</textarea></label>
<label>Remedial Work<textarea class="input" name="remedial_work" maxlength="12000" rows="3" @readonly(!$draft)>{{ old('remedial_work',$record['remedial_work']??'') }}</textarea></label>
@if($editing)
<fieldset style="padding:15px;border:1px solid #d9e2ea;border-radius:10px"><legend>Selected Job Photos (up to 8)</legend>
@forelse($photos as $photo)<label style="display:flex;gap:10px;align-items:center;padding:6px 0"><input type="checkbox" name="photo_ids[]" value="{{ $photo->id }}" @checked(in_array((int)$photo->id,old('photo_ids',$record['photo_ids']))) @disabled(!$draft)> Photo #{{ $photo->id }} · {{ ucfirst($photo->category) }} · {{ $photo->caption }}</label>
@empty<p class="muted">No saved job photos. Add photos through Photos & Notes first.</p>@endforelse</fieldset>
@endif
@if($draft)<div><button class="btn btn-primary" type="submit">Save Draft</button></div>@endif
</form>
</section>
@if($editing)
<section class="panel">
<div class="panel-header"><h2>Approval, Customer Signing and Documents</h2></div>
<div class="toolbar">
@if($draft)
<form method="post" action="{{ route('admin.acceptances.approve',$record['id']) }}" onsubmit="return confirm('Freeze this report for customer acceptance?')">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="revision" value="{{ $record['revision'] }}">
<button type="submit" class="btn btn-primary">Approve v{{ $record['revision'] }}</button></form>
@else
<a class="btn btn-outline" href="{{ route('admin.acceptances.pdf',[$record['id'],'approved']) }}">Approved PDF</a>
@if(in_array($record['status'],['approved','sent','viewed'],true))
<form method="post" action="{{ route('admin.acceptances.send',$record['id']) }}" onsubmit="return confirm('Email a secure acceptance link to the client?')">@csrf<input type="hidden" name="confirm" value="1"><button class="btn btn-primary" type="submit">Email Customer</button></form>
@endif
@if($record['has_signed_pdf'])
<a class="btn btn-primary" href="{{ route('admin.acceptances.pdf',[$record['id'],'signed']) }}">Download Signed PDF</a>
<a class="btn btn-outline" href="{{ route('admin.documents.show','managed-'.$record['managed_document_id']) }}">Saved Document</a>
@endif
@endif
</div></section>
@if($record['acceptance_result'])
<section class="panel"><div class="panel-header"><h2>Customer Response · {{ ucwords(str_replace('_',' ',$record['acceptance_result'])) }}</h2></div>
<div style="padding:20px;white-space:pre-wrap"><strong>Customer:</strong> {{ $record['customer_name'] }}
<strong>Reservation:</strong> {{ $record['customer_reservation']?:'None' }}
<strong>Outstanding:</strong> {{ $record['customer_outstanding_issues']?:'None' }}
<strong>Rating:</strong> {{ $record['customer_rating']??'Not rated' }}
<strong>Comment:</strong> {{ $record['customer_comment']?:'None' }}</div></section>
@endif
@if(!empty($customerPhotos))
<section class="panel">
<div class="panel-header"><h2>Original Customer Photo Evidence</h2></div>
<p style="padding:0 20px">The signed PDF also contains these photos. Each original is verified against its saved SHA-256 hash before download.</p>
<div class="table-wrap"><table><thead><tr><th>Photo</th><th>Type</th><th>Size</th><th>Added</th><th>Original</th></tr></thead><tbody>
@foreach($customerPhotos as $photo)
<tr><td>Customer Photo #{{ $photo['id'] }}</td><td>{{ $photo['mime_type'] }}</td><td>{{ number_format($photo['byte_size']) }} bytes</td><td>{{ $photo['created_at'] }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.acceptances.customerPhoto',[$record['id'],$photo['id']]) }}">Download Verified Original</a></td></tr>
@endforeach
</tbody></table></div>
</section>
@endif
<section class="panel"><div class="panel-header"><h2>Customer Signing Requests</h2></div>
<div class="table-wrap"><table><thead><tr><th>Request</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead><tbody>
@forelse($requests as $signing)
<tr><td>{{ $signing['request_uuid'] }}</td><td>{{ ucfirst($signing['status']) }}</td><td>{{ $signing['expires_at'] }}</td><td>
@if(in_array($signing['status'],['ready','sent','viewed'],true))
<form method="post" action="{{ route('admin.acceptances.revoke',[$record['id'],$signing['id']]) }}" onsubmit="return confirm('Revoke this customer signing link?')">@csrf<button class="btn btn-small btn-outline" type="submit">Revoke</button></form>
@endif</td></tr>
@empty<tr><td colspan="4">No requests sent yet.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Audit Trail</h2></div>
<div class="table-wrap"><table><thead><tr><th>Action</th><th>When</th><th>Actor</th></tr></thead><tbody>
@foreach($history as $event)<tr><td>{{ ucwords(str_replace('_',' ',$event['type'])) }}</td><td>{{ $event['created_at'] }}</td><td>{{ $event['actor_id']??'Customer / System' }}</td></tr>@endforeach
</tbody></table></div>
</section>
<section class="panel"><div class="panel-header"><h2>Draft Version History</h2></div><div class="table-wrap">
<table><thead><tr><th>Revision</th><th>Created</th><th>Actor</th></tr></thead><tbody>
@foreach($revisions as $revision)<tr><td>v{{ $revision['revision'] }}</td><td>{{ $revision['created_at'] }}</td><td>{{ $revision['actor_id']??'System' }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
