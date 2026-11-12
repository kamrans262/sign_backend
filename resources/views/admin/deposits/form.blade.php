@extends('admin.layout')
@section('title',isset($record)?'Manage Deposit':'Create Deposit')
@section('breadcrumb','Deposit Management')
@section('heading',isset($record)?'Manage Deposit':'Create Deposit')
@section('description','Cash and bank transfers are manually confirmed. A refund status never initiates a bank transfer.')
@section('content')
@php $editing=isset($record);$pending=!$editing||$record['status']==='pending'; @endphp
<section class="panel"><div class="panel-header"><div>
<h2>{{ $editing?'Deposit #'.$record['id']:'Record a Pending Deposit' }}</h2>
<p class="panel-header-hint">{{ $editing?ucfirst($record['status']):'Pending' }} · CHF</p></div>
<a class="btn btn-outline" href="{{ route('admin.deposits.index') }}">All Deposits</a></div>
<form class="form-grid" style="padding:20px" method="post" action="{{ $editing?route('admin.deposits.update',$record['id']):route('admin.deposits.store') }}">
@csrf @if($editing)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">@else<input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">@endif
@if(!$editing)
<label>Company <select name="company_id" class="input" required><option value="">Select Company</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach</select></label>
@endif
<label>Job <select class="input" name="job_id" required @disabled($editing)><option value="">Select Job</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(old('job_id',$record['job_id']??'')==$job->id)>{{ $job->reference_number }} · {{ $job->title }} (Company #{{ $job->company_id }})</option>@endforeach
</select>@if($editing)<input type="hidden" name="job_id" value="{{ $record['job_id'] }}">@endif</label>
<label>Approved Quote (optional) <select class="input" name="quote_id" @disabled($editing)><option value="">No quote linkage</option>
@foreach($quotes as $quote)<option value="{{ $quote->id }}" @selected(old('quote_id',$record['quote_id']??'')==$quote->id)>{{ $quote->reference_number }} · Job #{{ $quote->service_job_id }} (Company #{{ $quote->company_id }})</option>@endforeach
</select>@if($editing && $record['quote_id'])<input type="hidden" name="quote_id" value="{{ $record['quote_id'] }}">@endif</label>
<label>Client Name (from job) <input class="input" name="client_name" value="{{ old('client_name',$record['client_name']??'') }}" maxlength="255" placeholder="Leave blank to use saved job client" @readonly(!$pending)></label>
<label>Deposit Date <input class="input" type="date" name="deposit_date" required value="{{ old('deposit_date',$record['deposit_date']??date('Y-m-d')) }}" @readonly(!$pending)></label>
<label>Amount CHF <input class="input" name="amount" required inputmode="decimal" value="{{ old('amount',$editing?number_format($record['amount_minor']/100,2,'.',''):'') }}" placeholder="250.00" @readonly(!$pending)></label>
<label>Payment Method <select class="input" name="payment_method" @disabled(!$pending) required>
@foreach(['bank_transfer'=>'Bank Transfer','cash'=>'Cash','other'=>'Other Manual'] as $method=>$label)<option value="{{ $method }}" @selected(old('payment_method',$record['payment_method']??'bank_transfer')===$method)>{{ $label }}</option>@endforeach
</select>@if(!$pending)<input type="hidden" name="payment_method" value="{{ $record['payment_method'] }}">@endif</label>
<label id="deposit-custom-payment-row">Custom Payment Method (for Other Manual)
<input class="input" name="payment_method_custom" maxlength="120"
  value="{{ old('payment_method_custom',$record['payment_method_custom']??'') }}"
  placeholder="Enter the payment method" @readonly(!$pending)></label>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const method = document.querySelector('select[name="payment_method"]');
  const field = document.querySelector('input[name="payment_method_custom"]');
  const row = document.getElementById('deposit-custom-payment-row');
  if (!method || !field || !row) return;
  function refresh() {
    const custom = method.value === 'other';
    row.hidden = !custom;
    field.disabled = !custom;
    if (!custom) field.value = '';
  }
  method.addEventListener('change', refresh);
  refresh();
});
</script>
<label>Reference Number <input class="input" name="reference_number" maxlength="120" value="{{ old('reference_number',$record['reference_number']??'') }}" @readonly(!$pending)></label>
<label>Notes <textarea class="input" name="notes" maxlength="12000" rows="3" @readonly(!$pending)>{{ old('notes',$record['notes']??'') }}</textarea></label>
@if($pending)<div><button class="btn btn-primary">{{ $editing?'Update Deposit':'Create Pending Deposit' }}</button></div>@endif
</form></section>
@if($editing)
<section class="panel"><div class="panel-header"><h2>Payment Reconciliation</h2></div><div class="toolbar">
@if($record['status']==='pending')
<form method="post" action="{{ route('admin.deposits.receive',$record['id']) }}" data-confirm="Confirm the money was actually received outside Sign2Digital?">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<button class="btn btn-primary">Mark Received (Manual)</button></form>
<form method="post" action="{{ route('admin.deposits.destroy',$record['id']) }}" data-confirm="Void this pending request? This retains its audit history.">@csrf @method('DELETE')
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<input class="input" name="reason" minlength="8" maxlength="2000" placeholder="Reason to void" required>
<button class="btn btn-outline">Void Pending</button></form>
@endif
@if($record['status']==='received')
<form method="post" action="{{ route('admin.deposits.refund',$record['id']) }}" data-confirm="Was the refund already sent outside this app? This only records it.">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">
<input class="input" name="reason" minlength="8" maxlength="2000" placeholder="Manual refund reference/reason" required>
<button class="btn btn-outline">Record Refunded (Manual)</button></form>
@endif
@if($record['status']==='refunded')<p>Refund recorded: {{ $record['refunded_at'] }}. No bank payment was initiated.</p>@endif
</div></section>
<section class="panel"><div class="panel-header"><h2>Job & Quote Deposit Balance</h2></div><div style="padding:20px">
<p><strong>Pending requests:</strong> CHF {{ number_format($summary['pending_minor']/100,2) }}</p>
<p><strong>Confirmed received:</strong> CHF {{ number_format($summary['received_minor']/100,2) }}</p>
<p><strong>Recorded refunds:</strong> CHF {{ number_format($summary['refunded_minor']/100,2) }}</p>
@if($summary['expected_quote_deposit_minor']!==null)
<p><strong>Approved quote deposit:</strong> CHF {{ number_format($summary['expected_quote_deposit_minor']/100,2) }}</p>
<p><strong>Still to collect:</strong> CHF {{ number_format($summary['remaining_quote_deposit_to_collect_minor']/100,2) }}</p>
@endif
<p class="muted">Invoice application is not yet integrated; this view does not mark invoices paid.</p>
</div></section>
<section class="panel"><div class="panel-header"><h2>Original Receipts</h2></div>
@if($pending)<form class="toolbar" method="post" enctype="multipart/form-data" action="{{ route('admin.deposits.upload',$record['id']) }}">@csrf
<input class="input" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required>
<button class="btn btn-primary">Upload Receipt</button></form>@endif
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Bytes</th><th>SHA-256</th><th>Original</th><th>Action</th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr><td>{{ $receipt['name'] }}</td><td>{{ number_format($receipt['byte_size']) }}</td>
<td><code>{{ $receipt['sha256'] }}</code></td>
<td><a href="{{ route('admin.deposits.receipt',[$record['id'],$receipt['id']]) }}" class="btn btn-small btn-outline">Verified Original</a></td>
<td>@if($pending)<form method="post" action="{{ route('admin.deposits.removeReceipt',[$record['id'],$receipt['id']]) }}" data-confirm="Remove this receipt from the active view?">@csrf @method('DELETE')
<button class="btn btn-small btn-outline">Remove</button></form>@else Locked @endif</td>
</tr>@empty<tr><td colspan="5">No receipts recorded.</td></tr>@endforelse</tbody></table></div>
</section>
<section class="panel"><div class="panel-header"><h2>Immutable Deposit History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>Timestamp</th></tr></thead><tbody>
@forelse($history as $event)<tr><td>{{ ucwords(str_replace('_',' ',$event['type'])) }}</td>
<td>{{ $event['actor_user_id']??'System' }}</td><td>{{ $event['created_at'] }}</td></tr>
@empty<tr><td colspan="3">No activity recorded.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection