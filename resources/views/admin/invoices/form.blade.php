@extends('admin.layout')
@section('title',isset($record)?'Manage Invoice':'Create Invoice')
@section('breadcrumb','Invoice Management')
@section('heading',isset($record)?'Manage Invoice':'Create Invoice')
@section('description','Invoices use authoritative CHF totals. Payment confirmation never transfers money.')
@section('content')
@php
$editing=isset($record);
$invoice=$editing?$record['invoice']:null;
$draft=!$editing||$invoice['lifecycle_status']==='draft';
$items=old('items',$editing?$record['items']:[]);
$selectedAddenda=old('addenda_ids',$editing?$invoice['source_addenda_ids']:[]);
@endphp
<section class="panel"><div class="panel-header"><div>
<h2>{{ $editing?$invoice['number']:'Create Draft Invoice' }}</h2>
<p class="panel-header-hint">{{ $editing?ucwords(str_replace('_',' ',$invoice['status'])):'Draft' }} · CHF · No Stripe</p>
</div><a class="btn btn-outline" href="{{ route('admin.invoices.index') }}">All Invoices</a></div>
@if($editing)
<div class="toolbar">
<strong>Total: CHF {{ number_format($invoice['total_minor']/100,2) }}</strong>
<strong>Deposits: CHF {{ number_format($invoice['deposit_paid_minor']/100,2) }}</strong>
<strong>Payments: CHF {{ number_format($invoice['received_minor']/100,2) }}</strong>
<strong>Remaining: CHF {{ number_format($invoice['amount_due_minor']/100,2) }}</strong>
</div>
@endif
@if($draft)
<form class="form-grid" method="post" action="{{ $editing?route('admin.invoices.update',$invoice['id']):route('admin.invoices.store') }}" style="padding:20px">
@csrf @if($editing)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $invoice['lock_version'] }}">@else
<input type="hidden" name="request_key" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
<label>Company <select name="company_id" class="input" required><option value="">Select Company</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach</select></label>@endif
<label>Job / Project <select class="input" name="job_id" required @disabled($editing)>
<option value="">Select Job</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(old('job_id',$invoice['job_id']??'')==$job->id)>
{{ $job->reference_number }} · {{ $job->title }} (Company #{{ $job->company_id }})</option>@endforeach
</select>@if($editing)<input type="hidden" name="job_id" value="{{ $invoice['job_id'] }}">@endif</label>
<label>Approved Quote (optional) <select name="quote_id" class="input" @disabled($editing)>
<option value="">Not linked to a quote</option>
@foreach($quotes as $quote)<option value="{{ $quote->id }}" @selected(old('quote_id',$invoice['quote_id']??'')==$quote->id)>
{{ $quote->reference_number }} · Job #{{ $quote->service_job_id }} · Company #{{ $quote->company_id }}</option>@endforeach
</select>@if($editing && $invoice['quote_id'])<input type="hidden" name="quote_id" value="{{ $invoice['quote_id'] }}">@endif</label>
<label>Signed Addenda (optional, final invoices only)
<select class="input" name="addenda_ids[]" multiple size="5">
@foreach($addenda as $addendum)
<option value="{{ $addendum->id }}" @selected(in_array($addendum->id,array_map('intval',$selectedAddenda)))>
{{ $addendum->number }} · {{ $addendum->title }} · Job #{{ $addendum->service_job_id }} (Company #{{ $addendum->company_id }})
</option>
@endforeach
</select><small>Hold Ctrl / Command to select multiple signed addenda. Already invoiced items are rejected.</small></label>
<label>Invoice Type <select name="invoice_type" class="input" required>
@foreach(['final'=>'Final Invoice','deposit'=>'Deposit Invoice'] as $key=>$label)
<option value="{{ $key }}" @selected(old('invoice_type',$invoice['type']??'final')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Invoice Number <input class="input" name="invoice_number" maxlength="50" value="{{ old('invoice_number',$invoice['number']??'') }}" placeholder="Leave blank to generate a unique number" @readonly($editing)></label>
<label>Invoice Date <input class="input" type="date" name="invoice_date" required value="{{ old('invoice_date',$invoice['invoice_date']??date('Y-m-d')) }}"></label>
<label>Due Date <input class="input" type="date" name="due_date" required value="{{ old('due_date',$invoice['due_date']??date('Y-m-d',strtotime('+30 days'))) }}"></label>
<label>VAT Basis Points <input class="input" type="number" min="0" max="10000" name="vat_basis_points" value="{{ old('vat_basis_points',$invoice['vat_basis_points']??0) }}" required><small>810 = 8.10% VAT.</small></label>
<label>IBAN <input class="input" name="iban" maxlength="64" value="{{ old('iban',$invoice['iban']??'') }}"></label>
<label>Notes <textarea class="input" name="notes" rows="3" maxlength="12000">{{ old('notes',$invoice['notes']??'') }}</textarea></label>
<label>Terms <textarea class="input" name="terms" rows="3" maxlength="12000">{{ old('terms',$invoice['terms']??'') }}</textarea></label>
<label><input type="checkbox" name="allocate_deposits" value="1" @checked(old('allocate_deposits',($invoice['type']??'final')==='final'))>
 Apply available, actually received M16 job deposits once (final invoices only)</label>
<div style="grid-column:1/-1"><h3>Invoice Items</h3><p class="muted">Price values use CHF cents (9900 = CHF 99.00). Add up to 10 lines here; more are supported through the API.</p>
<div class="table-wrap"><table><thead><tr><th>#</th><th>Description</th><th>Quantity</th><th>Unit price (cents)</th></tr></thead><tbody>
@for($i=0;$i<max(10,count($items));$i++)
<tr><td>{{ $i+1 }}</td>
<td><input class="input" name="items[{{ $i }}][description]" maxlength="1000" value="{{ $items[$i]['description']??'' }}" placeholder="Work item"></td>
<td><input class="input" type="number" min="1" max="100000" name="items[{{ $i }}][quantity]" value="{{ $items[$i]['quantity']??1 }}"></td>
<td><input class="input" type="number" min="0" name="items[{{ $i }}][unit_price_minor]" value="{{ $items[$i]['unit_price_minor']??'' }}" placeholder="12500"></td></tr>
@endfor
</tbody></table></div></div>
<div><button class="btn btn-primary">Save Draft Invoice</button></div>
</form>
@else
<div style="padding:20px"><p>The approved invoice cannot be edited. Issued PDF and customer delivery will be added in Stage 2.</p></div>
@endif
</section>
@if($editing)
<section class="panel"><div class="panel-header"><h2>Review & Financial Actions</h2></div>
<div class="toolbar">
@if($invoice['lifecycle_status']==='draft')
<form method="post" action="{{ route('admin.invoices.approve',$invoice['id']) }}" onsubmit="return confirm('Approve and lock the reviewed invoice?')">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $invoice['lock_version'] }}">
<button class="btn btn-primary">Approve Invoice</button></form>
@endif
@if(in_array($invoice['lifecycle_status'],['draft','approved'],true) && $invoice['received_minor']===0)
<form method="post" action="{{ route('admin.invoices.voidInvoice',$invoice['id']) }}" onsubmit="return confirm('Void invoice and retain history?')">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $invoice['lock_version'] }}">
<input class="input" name="reason" minlength="8" maxlength="2000" required placeholder="Reason for void">
<button class="btn btn-outline">Void Invoice</button></form>
@endif
</div></section>
@if(in_array($invoice['lifecycle_status'],['approved','sent'],true) && $invoice['amount_due_minor']>0)
<section class="panel"><div class="panel-header"><h2>Record a Manual Payment</h2></div>
<form class="toolbar" method="post" action="{{ route('admin.invoices.payment',$invoice['id']) }}" onsubmit="return confirm('Has the money already been received outside Sign2Digital?')">@csrf
<input type="hidden" name="confirm" value="1">
<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<label>Amount CHF <input class="input" name="amount" inputmode="decimal" required placeholder="{{ number_format($invoice['amount_due_minor']/100,2,'.','') }}"></label>
<label>Date <input class="input" type="date" name="payment_date" required value="{{ date('Y-m-d') }}"></label>
<label>Method <select class="input" name="method"><option value="bank_transfer">Bank Transfer</option><option value="cash">Cash</option><option value="other">Other Manual</option></select></label>
<label>Reference <input class="input" name="reference_number" maxlength="120"></label>
<button class="btn btn-primary">Confirm Received</button></form></section>
@endif
<section class="panel"><div class="panel-header"><h2>Payment History (Immutable)</h2></div><div class="table-wrap"><table><thead>
<tr><th>ID</th><th>Amount CHF</th><th>Date</th><th>Method</th><th>Reference</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($record['payments'] as $payment)
<tr><td>#{{ $payment['id'] }}</td><td>{{ number_format($payment['amount_minor']/100,2) }}</td>
<td>{{ $payment['paid_at'] }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['reference_number'] }}</td>
<td>{{ $payment['status'] }}</td><td>@if($payment['status']==='paid')
<form method="post" action="{{ route('admin.invoices.voidPayment',[$invoice['id'],$payment['id']]) }}" onsubmit="return confirm('Void recorded payment, not a bank refund?')">@csrf
<input type="hidden" name="confirm" value="1"><input class="input" name="reason" minlength="8" maxlength="2000" required placeholder="Correction reason">
<button class="btn btn-small btn-outline">Void Record</button></form>@else Retained @endif</td></tr>
@empty<tr><td colspan="7">No payments have been confirmed.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Private Invoice Attachments</h2></div>
@if($draft)<form class="toolbar" method="post" enctype="multipart/form-data" action="{{ route('admin.invoices.upload',$invoice['id']) }}">@csrf
<input class="input" type="file" name="file" accept="image/jpeg,image/png,application/pdf" required>
<button class="btn btn-primary">Attach File</button></form>@endif
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Bytes</th><th>SHA-256</th><th>Download</th><th>Action</th></tr></thead><tbody>
@forelse($record['attachments'] as $attachment)
<tr><td>{{ $attachment['name'] }}</td><td>{{ number_format($attachment['byte_size']) }}</td>
<td><code>{{ $attachment['sha256'] }}</code></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.invoices.attachment',[$invoice['id'],$attachment['id']]) }}">Verified Original</a></td>
<td>@if($draft)<form method="post" action="{{ route('admin.invoices.removeAttachment',[$invoice['id'],$attachment['id']]) }}">@csrf @method('DELETE')<button class="btn btn-small btn-outline">Remove</button></form>@else Locked @endif</td></tr>
@empty<tr><td colspan="5">No attachments.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Invoice Audit History</h2></div><div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>Timestamp</th></tr></thead><tbody>
@forelse($record['history'] as $event)<tr><td>{{ ucwords(str_replace('_',' ',$event['type'])) }}</td><td>{{ $event['actor_user_id']??'System' }}</td><td>{{ $event['created_at'] }}</td></tr>
@empty<tr><td colspan="3">No activity.</td></tr>@endforelse</tbody></table></div></section>
@endif
@endsection