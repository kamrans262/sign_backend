@extends('admin.layout')
@section('title','Global Search')
@section('breadcrumb','Global Search')
@section('heading','Global Search')
@section('description','Search approved company records across existing modules. All changes remain in the original management screens.')
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Search Across the App</h2>
<p class="panel-header-hint">{{ number_format($result['pagination']['total']) }} matching records</p></div></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.search.index') }}">
<input class="input" type="search" name="q" aria-label="Search" maxlength="120" value="{{ $filters['q']??'' }}" placeholder="Search names and references">
<select class="input" name="company_id" aria-label="Company"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="types" aria-label="Type"><option value="">All Types</option>
@foreach($types as $type)<option value="{{ $type }}" @selected(($filters['types']??'')===$type)>{{ ucwords(str_replace('_',' ', $type)) }}</option>@endforeach</select>
<input class="input" name="client_id" type="number" min="1" aria-label="Client ID" placeholder="Client ID" value="{{ $filters['client_id']??'' }}">
<input class="input" name="job_id" type="number" min="1" aria-label="Job ID" placeholder="Job ID" value="{{ $filters['job_id']??'' }}">
<input class="input" name="document_type" maxlength="35" aria-label="Document Type" placeholder="Document Type" value="{{ $filters['document_type']??'' }}">
<input class="input" name="status" maxlength="25" aria-label="Status" placeholder="Status" value="{{ $filters['status']??'' }}">
<input class="input" name="date_from" type="date" aria-label="From Date" value="{{ $filters['date_from']??'' }}">
<input class="input" name="date_to" type="date" aria-label="To Date" value="{{ $filters['date_to']??'' }}">
<button class="btn btn-primary">Filter</button>
<a class="btn btn-outline" href="{{ route('admin.search.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Type</th><th>Record</th><th>Reference</th><th>Company</th><th>Status</th><th>Date</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr><td>{{ ucwords(str_replace('_',' ',$record['type'])) }}</td>
<td>{{ $record['title'] }}<div class="muted">{{ $record['subtitle'] }}</div></td>
<td>{{ $record['reference'] }}</td><td>#{{ $record['company_id'] }}</td>
<td><span class="pill">{{ ucwords(str_replace('_',' ',$record['status'])) }}</span></td>
<td>{{ $record['date'] }}</td>
<td><a class="btn btn-small btn-outline" href="{{ $record['url'] }}">Manage</a></td></tr>
@empty<tr><td colspan="7" class="empty">No matching records found.</td></tr>@endforelse
</tbody></table></div>
@if($result['pagination']['last_page']>1)<nav class="pagination">
@if($result['pagination']['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['pagination']['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['pagination']['page'] }} of {{ $result['pagination']['last_page'] }}</span>
@if($result['pagination']['has_more'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['pagination']['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>
@endsection
