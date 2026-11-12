@extends('admin.layout')
@section('title',$record?'Manage Reminder':'Create Reminder')
@section('breadcrumb','Notifications / Reminders')
@section('heading',$record?'Manage Reminder':'Create Reminder')
@section('description','Company ownership, private notes and audited reminder changes. Dates are entered and stored in UTC.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.reminders.index') }}">All Reminders</a>
@endsection
@section('content')
<section class="panel"><div class="panel-header"><h2>{{ $record?'Reminder #'.$record->id:'New Reminder' }}</h2></div>
<div style="padding:24px">
@if($record && $record->status==='archived')
<p>Archived reminders are read-only.</p>
@endif
<form method="post" action="{{ $record?route('admin.reminders.update',$record->id):route('admin.reminders.store') }}">
@csrf
@if($record)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $record->lock_version }}">@endif
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px">
<label>Company
<select class="input" style="display:block;width:100%" name="company_id" required>
<option value="">Select Company</option>
@foreach($companies as $company)
<option value="{{ $company->id }}" @selected(old('company_id',$record?->company_id)==$company->id)>{{ $company->name }}</option>
@endforeach
</select></label>
<label>Recipient / Owner
<select class="input" style="display:block;width:100%" name="owner_user_id" required>
<option value="">Select User</option>
@foreach($users as $user)
<option value="{{ $user->id }}" @selected(old('owner_user_id',$record?->owner_user_id)==$user->id)>{{ $user->name }} — {{ $user->email }} ({{ \App\Support\AdminCompanyNames::label($user->company_id) }})</option>
@endforeach</select></label>
<label>Title
<input class="input" style="display:block;width:100%" name="title" maxlength="160" required value="{{ old('title',$record?->title) }}"></label>
<label>Type
<select class="input" name="type" style="display:block;width:100%">
@foreach(['invoice','job','client','document','equipment','other'] as $type)
<option value="{{ $type }}" @selected(old('type',$record?->type ?? 'other')===$type)>{{ ucfirst($type) }}</option>@endforeach
</select></label>
<label>Due Date &amp; Time (UTC)
<input class="input" style="display:block;width:100%" name="due_at" type="datetime-local" required value="{{ old('due_at',$record?str_replace(' ','T',substr($record->due_at,0,16)):'') }}"></label>
<label>Follow-up Date &amp; Time (UTC)
<input class="input" style="display:block;width:100%" name="follow_up_at" type="datetime-local" value="{{ old('follow_up_at',$record?->follow_up_at?str_replace(' ','T',substr($record->follow_up_at,0,16)):'') }}"></label>
<label>Priority
<select class="input" name="priority" style="display:block;width:100%">
@foreach(['low','normal','high'] as $priority)
<option value="{{ $priority }}" @selected(old('priority',$record?->priority??'normal')===$priority)>{{ ucfirst($priority) }}</option>
@endforeach</select></label>
<label>Related Record ID (optional)
<input class="input" style="display:block;width:100%" name="related_id" type="number" min="1" value="{{ old('related_id',$record?->related_id) }}"></label>
<label>Notification Option
<select class="input" name="send_notification" style="display:block;width:100%">
<option value="1" @selected(old('send_notification',$record?->send_notification??1)==1)>Enable in-app notifications</option>
<option value="0" @selected(old('send_notification',$record?->send_notification??1)==0)>Disable notifications</option>
</select></label>
</div>
<div style="display:grid;gap:16px;max-width:920px;margin-top:18px">
<label>Description
<textarea class="input" name="description" style="display:block;width:100%" rows="3">{{ old('description',$record?->description) }}</textarea></label>
<label>Private Notes
<textarea class="input" name="private_notes" style="display:block;width:100%" rows="4">{{ old('private_notes',$record?->private_notes) }}</textarea>
<p class="muted">Internal only. Never included in in-app or push message templates.</p></label>
@if(!$record || $record->status!=='archived')
<button class="btn btn-primary" style="max-width:190px" type="submit">{{ $record?'Save Changes':'Create Reminder' }}</button>
@endif
</div>
</form>
@if($record && $record->status!=='archived')
<div style="display:flex;gap:12px;margin-top:18px">
@foreach(($record->status==='active'?['complete','archive']:['reopen','archive']) as $action)
<form method="post" action="{{ route('admin.reminders.status',$record->id) }}">@csrf
<input type="hidden" name="action" value="{{ $action }}">
<input type="hidden" name="lock_version" value="{{ $record->lock_version }}">
<button class="btn btn-outline" type="submit">{{ ucfirst($action) }}</button></form>
@endforeach
</div>
@endif
</div></section>
@if($record)
<section class="panel" style="margin-top:20px">
<div class="panel-header"><h2>Audit History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Action</th><th>Actor</th><th>Date</th></tr></thead><tbody>
@forelse($events as $event)
<tr><td>{{ $event->action }}</td><td>{{ $event->actor_user_id??'System' }}</td><td>{{ $event->created_at }}</td></tr>
@empty<tr><td colspan="3">No previous events recorded.</td></tr>@endforelse
</tbody></table></div>
</section>
@endif
@endsection