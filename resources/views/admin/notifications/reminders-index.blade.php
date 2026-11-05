@extends('admin.layout')
@section('title','Reminders Management')
@section('breadcrumb','Notifications / Reminders')
@section('heading','Reminders Management')
@section('description','Review real company-owned reminders and their status without activating push or external mail.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.reminders.create') }}">Create Reminder</a>
<a class="btn btn-outline" href="{{ route('admin.notifications.index') }}">Notification Templates</a>
<a class="btn btn-outline" href="{{ route('admin.notifications.inbox') }}">In-app Audit</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>Company Reminders ({{ number_format($reminders->total()) }})</h2></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.reminders.index') }}">
<input class="input" name="q" maxlength="160" placeholder="Search reminder title" value="{{ $filters['q'] ?? '' }}">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach
</select>
<select class="input" name="status"><option value="">All Statuses</option>
@foreach(['active'=>'Active','completed'=>'Completed','archived'=>'Archived'] as $key=>$label)
<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach</select>
<button class="btn btn-primary" type="submit">Filter</button>
<a class="btn btn-outline" href="{{ route('admin.reminders.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr>
<th>Title / Type</th><th>Company</th><th>Recipient</th><th>Due</th><th>Status</th><th>Notification</th><th>Manage</th>
</tr></thead><tbody>
@forelse($reminders as $record)
<tr><td><strong>{{ $record->title }}</strong><div class="muted">{{ ucfirst($record->type) }}</div></td>
<td>{{ $record->company_name }}</td><td>{{ $record->recipient_name }}</td>
<td>{{ $record->due_at }} UTC</td><td><span class="pill">{{ ucfirst($record->status) }}</span></td>
<td>{{ $record->send_notification?'Enabled':'Disabled' }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.reminders.edit',$record->id) }}">Manage</a></td></tr>
@empty<tr><td colspan="7">No reminders match the selected filters.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $reminders->links() }}</div>
</section>
@endsection