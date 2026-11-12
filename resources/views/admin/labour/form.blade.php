@extends('admin.layout')
@section('title',isset($record)?'Manage Labour Entry':'Add Labour Entry')
@section('breadcrumb','Time & Labour Management')
@section('heading',isset($record)?'Manage Labour Entry':'Add Labour Entry')
@section('description','Track approved employee hours and exact CHF rates with a retained audit history.')
@section('content')
@php $editing=isset($record);$pending=!$editing||$record['status']==='pending';$manual=!$editing||$record['source']==='manual'; @endphp
<section class="panel"><div class="panel-header"><div><h2>{{ $editing?'Entry #'.$record['id']:'Manual Labour or Time Sheet' }}</h2>
<p class="panel-header-hint">{{ $editing?ucfirst($record['status']):'Pending Review' }} · Switzerland / Europe-Zurich</p></div>
<a class="btn btn-outline" href="{{ route('admin.labour.index') }}">All Time Entries</a></div>
@if($editing && !$manual)<p style="padding:20px">Timer-generated time cannot be silently edited. Void with a reason and create a corrected manual record if needed.</p>@endif
<form class="form-grid" style="padding:20px" method="post" action="{{ $editing?route('admin.labour.update',$record['id']):route('admin.labour.store') }}">
@csrf @if($editing)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">@endif
@if(!$editing)<label>Company
<select class="input" name="company_id" required><option value="">Select Company</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach
</select></label>@endif
<label>Job<select class="input" name="job_id" required @disabled($editing)>
<option value="">Select Job</option>@foreach($jobs as $job)
<option value="{{ $job->id }}" @selected(old('job_id',$record['job_id']??'')==$job->id)>
{{ $job->reference_number }} — {{ $job->title }} ({{ \App\Support\AdminCompanyNames::label($job->company_id) }})</option>
@endforeach</select>@if($editing)<input type="hidden" name="job_id" value="{{ $record['job_id'] }}">@endif</label>
<label>Assigned Worker<select class="input" name="worker_id" required @disabled($editing)>
<option value="">Select Employee</option>@foreach($workers as $worker)
<option value="{{ $worker->id }}" @selected(old('worker_id',$record['worker_id']??'')==$worker->id)>{{ $worker->name }}</option>
@endforeach</select>@if($editing)<input type="hidden" name="worker_id" value="{{ $record['worker_id'] }}">@endif</label>
<label>Entry Type<select class="input" name="kind" required @disabled($editing)>
<option value="labour" @selected(old('kind',$record['kind']??'labour')==='labour')>Labour</option>
<option value="time_sheet" @selected(old('kind',$record['kind']??'labour')==='time_sheet')>Time Sheet</option>
</select>@if($editing)<input type="hidden" name="kind" value="{{ $record['kind'] }}">@endif</label>
<label>Worker Role<input class="input" name="worker_role" maxlength="120" value="{{ old('worker_role',$record['role']??'') }}" @readonly(!$pending||!$manual)></label>
<label>Work Date<input class="input" name="work_date" type="date" required value="{{ old('work_date',$record['work_date']??date('Y-m-d')) }}" @readonly(!$pending||!$manual)></label>
<label>Start Time<input class="input" type="time" name="start_clock" required value="{{ old('start_clock',$editing?sprintf('%02d:%02d',intdiv($record['start_minutes'],60),$record['start_minutes']%60):'08:00') }}" @readonly(!$pending||!$manual)></label>
<label>End Time<input class="input" type="time" name="end_clock" required value="{{ old('end_clock',$editing?sprintf('%02d:%02d',intdiv($record['end_minutes'],60),$record['end_minutes']%60):'17:00') }}" @readonly(!$pending||!$manual)></label>
<label>Hourly Rate (CHF, optional)<input class="input" name="hourly_rate" type="text" placeholder="45.00" value="{{ old('hourly_rate',$editing&&$record['hourly_rate_minor']!==null?number_format($record['hourly_rate_minor']/100,2,'.',''):'') }}" @readonly(!$pending||!$manual)></label>
<label>Task / Notes<textarea class="input" name="task" maxlength="12000" rows="4" @readonly(!$pending||!$manual)>{{ old('task',$record['task']??'') }}</textarea></label>
@if($pending && $manual)<div><button class="btn btn-primary" type="submit">{{ $editing?'Save Changes':'Create Pending Entry' }}</button></div>@endif
</form></section>

@if($editing)
<section class="panel"><div class="panel-header"><h2>Review & Corrections</h2></div>
<div class="toolbar">
<p><strong>Duration:</strong> {{ $record['duration_minutes'] }} minutes · <strong>Cost:</strong>
{{ $record['cost_minor']===null?'Not available — hourly rate missing':'CHF '.number_format($record['cost_minor']/100,2) }}</p>
@if($record['status']==='pending')
<form action="{{ route('admin.labour.approve',$record['id']) }}" method="post" data-confirm="Approve and lock the recorded labour?">@csrf
<input type="hidden" name="confirm" value="1"><button class="btn btn-primary">Approve Labour Entry</button></form>
@endif
@if($record['status']!=='void')
<form action="{{ route('admin.labour.void',$record['id']) }}" method="post" data-confirm="Void this entry? The original remains in audit history.">@csrf
<input class="input" name="reason" minlength="5" maxlength="2000" placeholder="Correction reason (required)" required>
<button class="btn btn-outline">Void with Reason</button></form>
@else<p>Voided: {{ $record['void_reason'] }}</p>@endif</div></section>
<section class="panel"><div class="panel-header"><h2>Job Labour & Purchase Summary</h2></div><div style="padding:20px">
<p><strong>Approved Minutes:</strong> {{ $summary['approved_minutes'] }} · <strong>Pending Minutes:</strong> {{ $summary['pending_minutes'] }}</p>
<p><strong>Approved Labour Cost:</strong> {{ $summary['approved_labour_cost_minor']===null?'Not available':'CHF '.number_format($summary['approved_labour_cost_minor']/100,2) }}</p>
<p><strong>Approved Purchase Cost:</strong> CHF {{ number_format($summary['approved_purchase_minor']/100,2) }}</p>
<p><strong>Overall Approved Job Cost:</strong> {{ $summary['overall_actual_cost_minor']===null?'Not available':'CHF '.number_format($summary['overall_actual_cost_minor']/100,2) }}</p>
@if($summary['unreconciled_external_labour_expense_minor']>0)<p>CHF {{ number_format($summary['unreconciled_external_labour_expense_minor']/100,2) }} of external labour purchase invoices excluded from combined cost pending reconciliation (prevents duplicate labour charging).</p>@endif
</div></section>
<section class="panel"><div class="panel-header"><h2>Private Evidence</h2></div>
@if($pending)<form class="toolbar" action="{{ route('admin.labour.upload',$record['id']) }}" method="post" enctype="multipart/form-data">@csrf
<input type="file" class="input" name="file" accept=".png,.jpg,.jpeg,.pdf" required>
<button class="btn btn-primary">Attach Evidence</button></form>@endif
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Size</th><th>SHA-256</th><th>File</th><th>Action</th></tr></thead><tbody>
@forelse($evidence as $file)<tr><td>{{ $file['name'] }}</td><td>{{ number_format($file['byte_size']) }} bytes</td><td><code>{{ $file['sha256'] }}</code></td><td><a class="btn btn-small btn-outline" href="{{ route('admin.labour.evidence.file',[$record['id'],$file['id']]) }}">Verified Original</a></td>
<td>@if($pending)<form method="post" action="{{ route('admin.labour.evidence.remove',[$record['id'],$file['id']]) }}" data-confirm="Hide this proof while preserving its audit?">@csrf @method('DELETE')<button class="btn btn-small btn-outline">Remove</button></form>@else Locked @endif</td></tr>
@empty<tr><td colspan="5">No evidence uploaded.</td></tr>@endforelse</tbody></table></div>
</section>
<section class="panel"><div class="panel-header"><h2>Audit History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>Timestamp</th></tr></thead><tbody>
@forelse($history as $event)<tr><td>{{ ucfirst($event['title']) }}</td><td>{{ $event['actor_user_id']??'System' }}</td><td>{{ $event['occurred_at'] }}</td></tr>
@empty<tr><td colspan="3">No history recorded.</td></tr>@endforelse</tbody></table></div>
</section>
@endif
@endsection
