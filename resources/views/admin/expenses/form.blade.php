@extends('admin.layout')
@section('title',isset($record)?'Manage Expense':'Create Expense')
@section('breadcrumb','Expenses Management')
@section('heading',isset($record)?'Manage Expense':'Create Expense')
@section('description','Recorded amounts and receipts are job-linked. Reviewed expenses are immutable.')
@section('content')
@php $editing=isset($record);$draft=!$editing||$record['status']==='to_review'; @endphp
<section class="panel"><div class="panel-header"><div>
<h2>{{ $editing?'Expense #'.$record['id']:'Record a Job Purchase' }}</h2>
<p class="panel-header-hint">{{ $editing?ucwords(str_replace('_',' ',$record['status'])):'To Review' }}</p></div>
<a class="btn btn-outline" href="{{ route('admin.expenses.index') }}">All Expenses</a></div>
<form class="form-grid" style="padding:20px" method="post" action="{{ $editing?route('admin.expenses.update',$record['id']):route('admin.expenses.store') }}">
@csrf @if($editing)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $record['lock_version'] }}">@endif
@if(!$editing)
<label>Company <select class="input" name="company_id" required><option value="">Select Company</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach</select></label>
@endif
<label>Job <select class="input" name="job_id" required @disabled(!$draft||$editing)><option value="">Select Job</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(old('job_id',$record['job_id']??'')==$job->id)>{{ $job->reference_number }} · {{ $job->title }} (Company #{{ $job->company_id }})</option>@endforeach
</select>@if($editing)<input type="hidden" name="job_id" value="{{ $record['job_id'] }}">@endif</label>
<label>Expense Type <select class="input" name="category" required @disabled(!$draft)>
@foreach(['material'=>'Material','fuel'=>'Fuel','tools'=>'Tools','parking'=>'Parking','meals'=>'Meals','labour'=>'Labour','other'=>'Other'] as $kind=>$label)
<option value="{{ $kind }}" @selected(old('category',$record['category']??'material')===$kind)>{{ $label }}</option>@endforeach</select>
@if(!$draft)<input type="hidden" name="category" value="{{ $record['category'] }}">@endif</label>
<label>Supplier <input class="input" name="supplier" maxlength="255" value="{{ old('supplier',$record['supplier']??'') }}" @readonly(!$draft)></label>
<label>Date <input class="input" type="date" name="expense_date" required value="{{ old('expense_date',$record['expense_date']??date('Y-m-d')) }}" @readonly(!$draft)></label>
<label>Amount (CHF) <input class="input" type="text" inputmode="decimal" name="amount" required value="{{ old('amount',$editing?number_format($record['amount_minor']/100,2,'.',''):'') }}" @readonly(!$draft) placeholder="98.00"></label>
<label>Description <textarea class="input" name="description" maxlength="12000" rows="4" @readonly(!$draft)>{{ old('description',$record['description']??'') }}</textarea></label>
<label>Tags (comma separated) <input class="input" name="tags_text" maxlength="900" value="{{ old('tags_text',implode(', ',$record['tags']??[])) }}" @readonly(!$draft)></label>
@if($draft)<div><button class="btn btn-primary" type="submit">{{ $editing?'Update Expense':'Create Expense' }}</button></div>@endif
</form></section>
@if($editing)
<section class="panel"><div class="panel-header"><h2>Review & Reimbursement</h2></div>
<div class="toolbar">
@if($record['status']==='to_review')<form method="post" action="{{ route('admin.expenses.approve',$record['id']) }}" onsubmit="return confirm('Approve and lock this expense?')">@csrf<input type="hidden" name="confirm" value="1"><button class="btn btn-primary" type="submit">Approve Expense</button></form>@endif
@if($record['status']==='approved' && $record['reimbursement_requested_at']===null)
<form method="post" action="{{ route('admin.expenses.requestReimbursement',$record['id']) }}" onsubmit="return confirm('Request reimbursement?')">@csrf<input type="hidden" name="confirm" value="1"><button class="btn btn-outline" type="submit">Request Reimbursement</button></form>@endif
@if($record['status']==='approved' && $record['reimbursement_requested_at']!==null)
<form method="post" action="{{ route('admin.expenses.reimburse',$record['id']) }}" onsubmit="return confirm('Confirm this reimbursement was actually paid?')">@csrf<input type="hidden" name="confirm" value="1"><button class="btn btn-primary" type="submit">Mark Reimbursed</button></form>@endif
@if($record['status']==='reimbursed')<p>Reimbursed on {{ $record['reimbursed_at'] }}</p>@endif
</div></section>
<section class="panel"><div class="panel-header"><h2>Job Expense Totals</h2></div><div style="padding:20px">
<p><strong>Total Job Expenses:</strong> CHF {{ number_format($totals['total_expenses_minor']/100,2) }}</p>
@if($totals['budget_minor']!==null)
<p><strong>Defined Job Budget:</strong> CHF {{ number_format($totals['budget_minor']/100,2) }}</p>
<p><strong>Remaining Budget:</strong> CHF {{ number_format($totals['remaining_budget_minor']/100,2) }}</p>
@else<p>No job budget has been defined. Remaining budget is not estimated.</p>@endif
</div></section>
<section class="panel"><div class="panel-header"><h2>Original Receipts</h2></div>
@if($draft)<form class="toolbar" method="post" enctype="multipart/form-data" action="{{ route('admin.expenses.upload',$record['id']) }}">@csrf
<input class="input" type="file" name="file" accept="image/png,image/jpeg,application/pdf" required>
<button type="submit" class="btn btn-primary">Upload Receipt</button></form>@endif
<div class="table-wrap"><table><thead><tr><th>Name</th><th>Size</th><th>SHA-256</th><th>Download</th><th>Action</th></tr></thead><tbody>
@forelse($receipts as $receipt)<tr><td>{{ $receipt['name'] }}</td><td>{{ number_format($receipt['byte_size']) }} bytes</td>
<td><code>{{ $receipt['sha256'] }}</code></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.expenses.receipt',[$record['id'],$receipt['id']]) }}">Verified Original</a></td><td>
@if($draft)<form method="post" action="{{ route('admin.expenses.removeReceipt',[$record['id'],$receipt['id']]) }}" onsubmit="return confirm('Remove this receipt from active view?')">@csrf @method('DELETE')<button class="btn btn-small btn-outline">Remove</button></form>@else Locked @endif
</td></tr>@empty<tr><td colspan="5">No receipts attached.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Expense History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Action</th><th>Actor</th><th>When</th></tr></thead><tbody>
@forelse($history as $event)<tr><td>{{ ucwords(str_replace('_',' ',$event['type'])) }}</td>
<td>{{ $event['actor_id']??'System' }}</td><td>{{ $event['created_at'] }}</td></tr>
@empty<tr><td colspan="3">No expense history recorded.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
