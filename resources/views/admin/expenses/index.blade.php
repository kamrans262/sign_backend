@extends('admin.layout')
@section('title','Expenses Management')
@section('breadcrumb','Expenses Management')
@section('heading','Expenses Management')
@section('description','Manage purchases by company and job, verified receipts, reviews and reimbursements.')
@section('header_actions')
<a href="{{ route('admin.expenses.create') }}" class="btn btn-primary">Add Expense</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Purchases & Expense Tracking</h2><p class="panel-header-hint">{{ number_format($result['total']) }} expenses</p></div></div>
<div class="toolbar"><form class="search" method="get" action="{{ route('admin.expenses.index') }}">
<input class="input" type="search" name="q" placeholder="Supplier, job, reference" value="{{ $filters['q']??'' }}">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="job_id"><option value="">All Jobs</option>@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(($filters['job_id']??'')==$job->id)>{{ $job->reference_number }} · {{ $job->title }}</option>@endforeach</select>
<select class="input" name="status"><option value="">All Statuses</option>@foreach(['to_review'=>'To Review','approved'=>'Approved','reimbursed'=>'Reimbursed'] as $v=>$label)<option value="{{ $v }}" @selected(($filters['status']??'')===$v)>{{ $label }}</option>@endforeach</select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.expenses.index') }}">Clear</a></form></div>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Job</th><th>Supplier</th><th>Type</th><th>Date</th><th>Amount CHF</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr><td>#{{ $record['id'] }}</td><td>{{ $record['job'] }}<div class="muted">{{ $record['job_reference'] }}</div></td>
<td>{{ $record['supplier']?:'Not provided' }}</td><td>{{ ucfirst($record['category']) }}</td>
<td>{{ $record['expense_date'] }}</td><td>{{ number_format($record['amount_minor']/100,2) }}</td>
<td><span class="pill">{{ ucwords(str_replace('_',' ',$record['status'])) }}</span></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.expenses.edit',$record['id']) }}">Manage</a></td></tr>
@empty<tr><td class="empty" colspan="8">No recorded expenses found.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>
@endsection
