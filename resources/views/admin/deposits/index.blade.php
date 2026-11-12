@extends('admin.layout')
@section('title','Deposit Management')
@section('breadcrumb','Deposit Management')
@section('heading','Deposit Management')
@section('description','Track requested, received and refunded CHF deposits without charging a bank or card.')
@section('header_actions')
<a href="{{ route('admin.deposits.create') }}" class="btn btn-primary">Add Deposit</a>
<a href="{{ route('admin.deposits.export',request()->query()) }}" class="btn btn-outline">Export CSV</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Deposit Management</h2><p class="panel-header-hint">{{ number_format($result['total']) }} deposits · manual reconciliation</p></div></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.deposits.index') }}">
<input class="input" type="search" name="q" value="{{ $filters['q']??'' }}" placeholder="Client, job or reference">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="job_id"><option value="">All Jobs</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(($filters['job_id']??'')==$job->id)>{{ $job->reference_number }} · {{ $job->title }}</option>@endforeach</select>
<select class="input" name="status"><option value="">Active Statuses</option>
@foreach(['pending'=>'Pending','received'=>'Received','refunded'=>'Refunded','void'=>'Voided'] as $v=>$label)<option value="{{ $v }}" @selected(($filters['status']??'')===$v)>{{ $label }}</option>@endforeach
</select>
<button class="btn btn-primary">Filter</button><a href="{{ route('admin.deposits.index') }}" class="btn btn-outline">Clear</a></form></div>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Job / Quote</th><th>Client</th><th>Date</th><th>Amount CHF</th><th>Method</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $row)<tr>
<td>#{{ $row['id'] }}</td>
<td>{{ $row['job'] }}<div class="muted">{{ $row['job_reference'] }} @if($row['quote_id']) · Quote #{{ $row['quote_id'] }} @endif</div></td>
<td>{{ $row['client_name'] }}</td><td>{{ $row['deposit_date'] }}</td>
<td>{{ number_format($row['amount_minor']/100,2) }}</td>
<td>{{ $row['payment_method']==='other' && !empty($row['payment_method_custom']) ? $row['payment_method_custom'] : ucwords(str_replace('_',' ',$row['payment_method'])) }}</td>
<td><span class="pill">{{ ucfirst($row['status']) }}</span></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.deposits.edit',$row['id']) }}">Manage</a></td>
</tr>@empty<tr><td colspan="8" class="empty">No deposits found.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>
<section class="panel">
<div class="panel-header"><div><h2>Create from an Approved Quote</h2><p class="panel-header-hint">Requests only the unrequested quote deposit balance; does not collect money.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Quote</th><th>Company / Job</th><th>Approved deposit CHF</th><th>Action</th></tr></thead><tbody>
@forelse($quotes as $quote)<tr>
<td>{{ $quote->reference_number }}</td><td>{{ \App\Support\AdminCompanyNames::label($quote->company_id) }} · Job #{{ $quote->service_job_id }}</td>
<td>{{ number_format($quote->deposit_minor/100,2) }}</td>
<td><form method="post" action="{{ route('admin.deposits.fromQuote',$quote->id) }}" data-confirm="Create a pending deposit from the approved quote?">@csrf
<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
<button class="btn btn-small btn-outline">Request Deposit</button></form></td></tr>
@empty<tr><td colspan="4">No approved quotes with deposit amounts yet.</td></tr>@endforelse
</tbody></table></div></section>
@endsection