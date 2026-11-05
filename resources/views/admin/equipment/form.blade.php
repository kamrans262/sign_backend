@extends('admin.layout')
@section('title',$record?'Manage Equipment':'Add Equipment')
@section('breadcrumb','Equipment & Loans Management')
@section('heading',$record?'Manage Equipment':'Add Equipment')
@section('description','Equipment assets are separate from loans and preserve maintenance history.')
@section('content')
<section class="panel">
<div class="panel-header"><h2>{{ $record?'Equipment '.$record['code']:'New Equipment Asset' }}</h2>
<a class="btn btn-outline" href="{{ route('admin.equipment.index') }}">All Equipment</a></div>
<form class="form-grid" style="padding:20px" method="post" action="{{ $record?route('admin.equipment.update',$record['id']):route('admin.equipment.store') }}">
@csrf @if($record)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">@endif
@if(!$record)<label>Company<select name="company_id" class="input" required><option value="">Choose Company</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected(old('company_id')==$c->id)>{{ $c->name }}</option>@endforeach</select></label>@endif
@foreach(['code'=>'Equipment ID','name'=>'Name','category'=>'Category','brand'=>'Brand','model'=>'Model'] as $field=>$label)
<label>{{ $label }} <input class="input" name="{{ $field }}" maxlength="{{ $field==='code'?80:255 }}" required value="{{ old($field,$record[$field]??'') }}"></label>@endforeach
<label>Purchase Date<input class="input" type="date" name="purchase_date" required value="{{ old('purchase_date',$record['purchase_date']??date('Y-m-d')) }}"></label>
<label>Status<select class="input" name="status">@foreach(['available'=>'Available','in_use'=>'In Use','maintenance'=>'Maintenance'] as $key=>$label)<option value="{{ $key }}" @selected(old('status',$record['status']??'available')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Current Location<input class="input" name="location" maxlength="255" value="{{ old('location',$record['location']??'') }}"></label>
<label>Initial Hours<input class="input" type="number" step="0.01" min="0" name="initial_hours" value="{{ old('initial_hours',$record['initial_hours']??'0') }}"></label>
<label style="grid-column:1/-1">Notes<textarea class="input" rows="3" name="notes" maxlength="6000">{{ old('notes',$record['notes']??'') }}</textarea></label>
<div><button class="btn btn-primary" type="submit">{{ $record?'Update Equipment':'Register Equipment' }}</button></div>
</form>
</section>
@if($record)
<section class="panel"><div class="panel-header"><h2>Current Loan & Lending History</h2></div>
<div class="toolbar">@if($record['current_loan_id'])
<a class="btn btn-primary" href="{{ route('admin.equipment.loan.edit',$record['current_loan_id']) }}">Manage Current Loan #{{ $record['current_loan_id'] }}</a>
@else<a class="btn btn-outline" href="{{ route('admin.equipment.loan.create') }}">Create Equipment Loan</a>@endif
</div>
<div class="table-wrap"><table><thead><tr><th>Borrower</th><th>Loan</th><th>Due</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($loans['records'] as $loan)<tr><td>{{ $loan['borrower_name'] }}</td><td>{{ $loan['loan_date'] }}</td><td>{{ $loan['expected_return_date'] }}</td><td>{{ $loan['status'] }}</td><td><a href="{{ route('admin.equipment.loan.edit',$loan['id']) }}" class="btn btn-small btn-outline">Details</a></td></tr>
@empty<tr><td colspan="5">Never lent.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Usage & Maintenance</h2></div>
<div class="form-grid" style="padding:20px">
<form method="post" action="{{ route('admin.equipment.usage',$record['id']) }}">@csrf
<h3>Record Usage</h3>
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<label>Date<input class="input" type="date" name="date" value="{{ date('Y-m-d') }}" required></label>
<label>Hours<input class="input" type="number" min="0.01" step="0.01" name="hours" required></label>
<label>Job ID (optional)<input class="input" name="job_id" type="number" min="1"></label>
<label>Notes<textarea class="input" name="notes" rows="2"></textarea></label>
<button class="btn btn-primary" type="submit">Record Usage</button></form>
<form method="post" action="{{ route('admin.equipment.maintenance',$record['id']) }}">@csrf
<h3>Schedule Maintenance</h3>
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<label>Date<input class="input" type="date" name="date" value="{{ date('Y-m-d') }}" required></label>
<label>Description<textarea class="input" name="description" required maxlength="2000"></textarea></label>
<label><input type="checkbox" name="completed" value="1"> Completed</label>
<button class="btn btn-primary" type="submit">Save Maintenance</button></form>
</div>
<div class="table-wrap"><table><thead><tr><th>Usage Date</th><th>Hours</th><th>Notes</th></tr></thead><tbody>
@forelse($record['usage'] as $row)<tr><td>{{ $row['date'] }}</td><td>{{ $row['hours'] }}</td><td>{{ $row['notes'] }}</td></tr>
@empty<tr><td colspan="3">No usage entries.</td></tr>@endforelse
</tbody></table></div><div class="table-wrap"><table><thead><tr><th>Maintenance</th><th>Scheduled</th><th>Completed</th></tr></thead><tbody>
@forelse($record['maintenance'] as $row)<tr><td>{{ $row['description'] }}</td><td>{{ $row['date'] }}</td><td>{{ $row['completed']?'Yes':'No' }}</td></tr>
@empty<tr><td colspan="3">No maintenance records.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Private Photos</h2></div>
<form class="toolbar" method="post" action="{{ route('admin.equipment.photo.upload',$record['id']) }}" enctype="multipart/form-data">@csrf
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<input class="input" type="file" name="file" required accept="image/jpeg,image/png">
<button class="btn btn-primary">Upload Photo</button></form>
<div class="table-wrap"><table><thead><tr><th>Name</th><th>SHA-256</th><th>Download</th><th>Remove</th></tr></thead><tbody>
@forelse($record['photos'] as $p)<tr><td>{{ $p['name'] }}</td><td><code>{{ $p['sha256'] }}</code></td><td><a class="btn btn-small btn-outline" href="{{ route('admin.equipment.photo.file',[$record['id'],$p['id']]) }}">Verified Original</a></td>
<td><form method="post" action="{{ route('admin.equipment.photo.remove',[$record['id'],$p['id']]) }}">@csrf @method('DELETE')
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}"><button class="btn btn-small btn-outline">Remove</button></form></td></tr>
@empty<tr><td colspan="4">No photos.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Equipment Audit</h2></div><div class="table-wrap"><table><thead><tr><th>Action</th><th>Actor</th><th>Date</th></tr></thead><tbody>
@foreach($record['history'] as $event)<tr><td>{{ $event['type'] }}</td><td>{{ $event['actor_id']??'System' }}</td><td>{{ $event['created_at'] }}</td></tr>@endforeach
</tbody></table></div></section>
@if(!$record['current_loan_id'])
<section class="panel"><div class="panel-header"><h2>Archive Equipment</h2></div>
<form class="toolbar" method="post" action="{{ route('admin.equipment.archive',$record['id']) }}" onsubmit="return confirm('Archive this equipment? Loan history will remain.')">
@csrf<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<button class="btn btn-outline">Archive Equipment</button></form></section>
@endif
@endif
@endsection
