@extends('admin.layout')
@section('title',$record?'Manage Equipment Loan':'Create Equipment Loan')
@section('breadcrumb','Equipment & Loans Management')
@section('heading',$record?'Equipment Loan #'.$record['id']:'Create Equipment Loan')
@section('description','Equipment assets and individual loans remain separate and auditable.')
@section('content')
<section class="panel">
<div class="panel-header"><h2>{{ $record?'Borrower & Loan Details':'Record Equipment Loan' }}</h2>
<a class="btn btn-outline" href="{{ route('admin.equipment.loans') }}">All Loans</a></div>
@if(!$record)
<form class="form-grid" style="padding:20px" method="post" action="{{ route('admin.equipment.loan.store') }}">@csrf
<label>Company<select class="input" name="company_id" required><option value="">Choose Company</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected(old('company_id')==$c->id)>{{ $c->name }}</option>@endforeach</select></label>
<label>Equipment<select class="input" name="equipment_asset_id" required><option value="">Choose Equipment</option>@foreach($assets as $a)<option value="{{ $a->id }}" @selected(old('equipment_asset_id')==$a->id)>{{ $a->code }} · {{ $a->name }} (Company #{{ $a->company_id }})</option>@endforeach</select></label>
<label>Borrower Type<select class="input" name="borrower_type"><option value="external">Other Person</option><option value="client" @selected(old('borrower_type')==='client')>Existing Client</option></select></label>
<label>Client (required if linked)<select class="input" name="client_id"><option value="">Other Person / No Client</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected(old('client_id')==$c->id)>{{ $c->full_name }} (Company #{{ $c->company_id }})</option>@endforeach</select></label>
<label>Borrower Full Name<input class="input" name="borrower_name" required maxlength="255" value="{{ old('borrower_name') }}"></label>
<label>Borrower Email (optional)<input class="input" name="borrower_email" type="email" maxlength="255" value="{{ old('borrower_email') }}"></label>
<label>Job (optional)<select class="input" name="job_id"><option value="">No Job</option>@foreach($jobs as $j)<option value="{{ $j->id }}" @selected(old('job_id')==$j->id)>{{ $j->title }} (Company #{{ $j->company_id }})</option>@endforeach</select></label>
<label>Loan Date<input class="input" type="date" name="loan_date" required value="{{ old('loan_date',date('Y-m-d')) }}"></label>
<label>Expected Return<input class="input" type="date" name="expected_return_date" required value="{{ old('expected_return_date',date('Y-m-d')) }}"></label>
<label style="grid-column:1/-1">Notes<textarea class="input" name="notes" rows="3" maxlength="6000">{{ old('notes') }}</textarea></label>
<div><button class="btn btn-primary" type="submit">Create Loan</button></div>
</form>
@else
<div style="padding:20px">
<p><strong>Equipment:</strong> {{ $record['equipment_code'] }} · {{ $record['equipment_name'] }}</p>
<p><strong>Borrower:</strong> {{ $record['borrower_name'] }} ({{ $record['borrower_type'] }})</p>
<p><strong>Loan Date:</strong> {{ $record['loan_date'] }}</p>
<p><strong>Expected Return:</strong> {{ $record['expected_return_date'] }}</p>
<p><strong>Actual Return:</strong> {{ $record['actual_return_date']??'Not returned' }}</p>
<p><strong>Status:</strong> <span class="pill">{{ ucfirst($record['status']) }}</span></p>
<p><strong>Linked Client:</strong> {{ $record['client_id']??'Not linked' }} · <strong>Job:</strong> {{ $record['job_id']??'Not linked' }}</p>
</div>
@if($record['stored_status']==='active')
<form method="post" class="form-grid" style="padding:20px" action="{{ route('admin.equipment.loan.update',$record['id']) }}">
@csrf @method('PATCH')<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<label>Contact Email <input class="input" name="borrower_email" type="email" value="{{ $record['borrower_email'] }}" maxlength="255"></label>
<label>Notes <textarea class="input" name="notes" maxlength="6000">{{ $record['notes'] }}</textarea></label>
<div><button class="btn btn-outline">Update Details</button></div>
</form>
<div class="form-grid" style="padding:20px">
<form method="post" action="{{ route('admin.equipment.loan.extend',$record['id']) }}" onsubmit="return confirm('Extend the expected return date?')">
@csrf<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}"><input type="hidden" name="confirm" value="1">
<label>New Return Date<input class="input" type="date" name="expected_return_date" required min="{{ $record['expected_return_date'] }}"></label>
<button type="submit" class="btn btn-outline">Extend Return Date</button></form>
<form method="post" action="{{ route('admin.equipment.loan.return',$record['id']) }}" onsubmit="return confirm('Confirm this equipment was returned?')">
@csrf<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}"><input type="hidden" name="confirm" value="1">
<label>Actual Return Date<input class="input" type="date" name="actual_return_date" required min="{{ $record['loan_date'] }}" value="{{ date('Y-m-d') }}"></label>
<button class="btn btn-primary" type="submit">Mark Returned</button></form>
</div>
<p style="padding:0 20px">Email reminders are not yet configured; no borrower has been contacted. Reminder sending is Stage 2.</p>
@endif
@endif
</section>
@if($record)
<section class="panel"><div class="panel-header"><h2>Immutable Loan History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>When</th><th>Details</th></tr></thead><tbody>
@forelse($history as $e)<tr><td>{{ ucwords(str_replace('_',' ',$e['type'])) }}</td><td>{{ $e['actor_id']??'System' }}</td><td>{{ $e['created_at'] }}</td>
<td>@foreach($e['metadata'] as $key=>$value)<div>{{ $key }}: {{ is_scalar($value)?$value:'—' }}</div>@endforeach</td></tr>
@empty<tr><td colspan="4">No history.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
