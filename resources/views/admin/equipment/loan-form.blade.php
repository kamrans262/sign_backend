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
<form method="post" action="{{ route('admin.equipment.loan.extend',$record['id']) }}" data-confirm="Extend the expected return date?">
@csrf<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}"><input type="hidden" name="confirm" value="1">
<label>New Return Date<input class="input" type="date" name="expected_return_date" required min="{{ $record['expected_return_date'] }}"></label>
<button type="submit" class="btn btn-outline">Extend Return Date</button></form>
<form method="post" action="{{ route('admin.equipment.loan.return',$record['id']) }}" data-confirm="Confirm this equipment was returned?">
@csrf<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}"><input type="hidden" name="confirm" value="1">
<label>Actual Return Date<input class="input" type="date" name="actual_return_date" required min="{{ $record['loan_date'] }}" value="{{ date('Y-m-d') }}"></label>
<button class="btn btn-primary" type="submit">Mark Returned</button></form>
</div>
<div style="padding:20px">
<h3>Equipment Return Reminders</h3>
<p>Manual sending requires your approval and configured real outbound email. Submitted does not mean received.</p>
@if(!$reminderPreview['transport_ready'])
<p class="muted">Outbound mail is not configured; no borrower email can be sent.</p>
@endif
<form method="post" action="{{ route('admin.equipment.loan.reminder.policy',$record['id']) }}">
@csrf @method('PUT')
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<input type="hidden" name="automatic_enabled" value="0">
<label><input type="checkbox" name="automatic_enabled" value="1" @checked($reminderPreview['automatic_enabled'])>
Enable overdue email reminders for this loan</label>
<input type="hidden" name="recipient_confirmed" value="0">
<label><input type="checkbox" name="recipient_confirmed" value="1" @checked($reminderPreview['recipient_confirmed'])>
I confirm this borrower email belongs to the intended recipient.</label>
<label>Repeat every (days)
<input class="input" type="number" name="frequency_days" min="1" max="30"
value="{{ $reminderPreview['frequency_days'] }}" required></label>
@if(!$reminderPreview['automatic_global_enabled'])
<p class="muted">Automatic sending is globally disabled until explicitly enabled during deployment.</p>
@endif
<button class="btn btn-outline" type="submit">Save Reminder Preferences</button>
</form>
<div style="margin-top:20px">
<h3>Send a One-Time Email</h3>
<p><strong>To:</strong> {{ $reminderPreview['borrower_email']?:'No borrower email' }}</p>
<p><strong>Equipment:</strong> {{ $reminderPreview['equipment_name'] }}</p>
<p><strong>Due:</strong> {{ $reminderPreview['expected_return_date'] }}</p>
<form method="post" action="{{ route('admin.equipment.loan.reminder.send',$record['id']) }}"
data-confirm="Submit this equipment return email to the displayed borrower?">
@csrf
<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="confirm" value="1">
<label><input type="checkbox" name="recipient_confirmed" value="1" required>
Confirm recipient email and submit one email only</label>
<button type="submit" class="btn btn-primary" @disabled(!$reminderPreview['send_available'])>
Send Reminder Email</button>
</form>
</div>
</div>
@endif
@endif
</section>
@if($record)
<section class="panel"><div class="panel-header"><h2>Reminder Delivery History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Kind</th><th>Due</th><th>Status</th><th>Attempts</th><th>Recorded</th></tr></thead><tbody>
@forelse($reminderPreview['delivery_history'] as $delivery)
<tr><td>{{ ucfirst($delivery['kind']) }}</td><td>{{ $delivery['due_date'] }}</td>
<td>{{ $delivery['status'] }}</td><td>{{ $delivery['attempt_count'] }}</td>
<td>{{ $delivery['created_at'] }} <span class="muted">{{ $delivery['delivery_note'] }}</span></td></tr>
@empty<tr><td colspan="5">No reminders requested. No email has been sent from this loan.</td></tr>
@endforelse
</tbody></table></div></section>
@endif
@if($record)
<section class="panel"><div class="panel-header"><h2>Immutable Loan History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>When</th><th>Details</th></tr></thead><tbody>
@forelse($history as $e)<tr><td>{{ ucwords(str_replace('_',' ',$e['type'])) }}</td><td>{{ $e['actor_id']??'System' }}</td><td>{{ $e['created_at'] }}</td>
<td>@foreach($e['metadata'] as $key=>$value)<div>{{ $key }}: {{ is_scalar($value)?$value:'—' }}</div>@endforeach</td></tr>
@empty<tr><td colspan="4">No history.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
