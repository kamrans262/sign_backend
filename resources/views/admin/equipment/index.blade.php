@extends('admin.layout')
@section('title','Equipment & Loans Management')
@section('breadcrumb','Equipment & Loans Management')
@section('heading','Equipment & Loans Management')
@section('description','Manage company equipment, operational usage and separate lending records.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.equipment.loans') }}">Equipment Loans</a>
<a class="btn btn-primary" href="{{ route('admin.equipment.create') }}">Add Equipment</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Equipment Assets</h2><p class="panel-header-hint">{{ $result['total'] }} registered assets</p></div></div>
<div class="toolbar"><form class="search" method="get">
<input class="input" type="search" name="q" placeholder="Equipment name, code, category" value="{{ $filters['q']??'' }}">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="status"><option value="">All Statuses</option>
@foreach(['available'=>'Available','in_use'=>'In Use','maintenance'=>'Maintenance'] as $id=>$label)
<option value="{{ $id }}" @selected(($filters['status']??'')===$id)>{{ $label }}</option>@endforeach</select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.equipment.index') }}">Clear</a></form></div>
<div class="table-wrap"><table><thead><tr><th>Code</th><th>Equipment</th><th>Company</th><th>Status</th><th>Hours</th><th>Current Loan</th><th>Manage</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr><td>{{ $record['code'] }}</td><td>{{ $record['name'] }}<div class="muted">{{ $record['brand'] }} · {{ $record['model'] }}</div></td>
<td>{{ \App\Support\AdminCompanyNames::label($record['company_id'] ?? null) }}</td><td><span class="pill">{{ ucwords(str_replace('_',' ',$record['status'])) }}</span></td>
<td>{{ number_format($record['hours'],2) }}</td><td>{{ $record['current_loan_id']?'Loan #'.$record['current_loan_id']:'None' }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.equipment.edit',$record['id']) }}">Manage</a></td></tr>
@empty<tr><td colspan="7" class="empty">No equipment registered.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>@endsection
