@extends('admin.layout')
@section('title','Invoice Management')
@section('breadcrumb','Invoice Management')
@section('heading','Invoice Management')
@section('description','Company-scoped invoice drafts, verified deposit credits, manual payments and outstanding balances.')
@section('header_actions')
<a href="{{ route('admin.invoices.create') }}" class="btn btn-primary">Create Invoice</a>
<a href="{{ route('admin.invoices.export',request()->query()) }}" class="btn btn-outline">Export CSV</a>
@endsection
@section('content')
<section class="panel"><div class="panel-header"><div>
<h2>Invoicing & Payment Management</h2>
<p class="panel-header-hint">{{ number_format($result['total']) }} matching invoices · CHF</p></div></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.invoices.index') }}">
<input class="input" type="search" name="q" value="{{ $filters['q']??'' }}" placeholder="Invoice, customer or job">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="status"><option value="">All Statuses</option>
@foreach(['draft'=>'Draft','pending'=>'Pending','partially_paid'=>'Partially Paid','paid'=>'Paid','sent'=>'Sent','overdue'=>'Overdue','void'=>'Void'] as $key=>$value)
<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $value }}</option>@endforeach
</select>
<select class="input" name="type"><option value="">All Types</option>
<option value="deposit" @selected(($filters['type']??'')==='deposit')>Deposit Invoice</option>
<option value="final" @selected(($filters['type']??'')==='final')>Final Invoice</option></select>
<button class="btn btn-primary">Filter</button>
<a href="{{ route('admin.invoices.index') }}" class="btn btn-outline">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr>
<th>Invoice</th><th>Job / Company</th><th>Customer</th><th>Type</th><th>Issue / Due</th>
<th>Total CHF</th><th>Credited CHF</th><th>Due CHF</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr><td>{{ $record['number'] }}</td>
<td>{{ $record['job'] }}<div class="muted">#{{ $record['job_id'] }} · Company #{{ $record['company_id'] }}</div></td>
<td>{{ $record['client_name'] }}</td><td>{{ ucfirst($record['type']) }}</td>
<td>{{ $record['invoice_date'] }}<div class="muted">{{ $record['due_date'] }}</div></td>
<td>{{ number_format($record['total_minor']/100,2) }}</td>
<td>{{ number_format(($record['deposit_paid_minor']+$record['received_minor'])/100,2) }}</td>
<td>{{ number_format($record['amount_due_minor']/100,2) }}</td>
<td><span class="pill">{{ ucwords(str_replace('_',' ',$record['status'])) }}</span></td>
<td><a href="{{ route('admin.invoices.edit',$record['id']) }}" class="btn btn-small btn-outline">Manage</a></td>
</tr>@empty<tr><td colspan="10" class="empty">No invoices recorded yet.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>
@endsection