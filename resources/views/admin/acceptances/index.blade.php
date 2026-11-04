@extends('admin.layout')
@section('title','Work Acceptance Management')
@section('breadcrumb','Work Acceptance Management')
@section('heading','Work Acceptance Management')
@section('description','Manage work-completion reports, customer reservations and immutable signed evidence.')
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Customer Work Acceptance</h2><p class="panel-header-hint">{{ number_format($result['total']) }} records</p></div><a class="btn btn-primary" href="{{ route('admin.acceptances.create') }}">Create Acceptance</a></div>
<div class="toolbar">
<form class="search" method="get" action="{{ route('admin.acceptances.index') }}">
<input class="input" name="q" type="search" placeholder="Search reference, job, customer" value="{{ $filters['q']??'' }}">
<select class="input" name="company_id"><option value="">All companies</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<select class="input" name="status"><option value="">All statuses</option>
@foreach(['draft'=>'Draft','approved'=>'Approved','sent'=>'Sent','viewed'=>'Viewed','accepted'=>'Accepted','accepted_with_reservations'=>'Accepted with Reservations'] as $status=>$text)
<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ $text }}</option>@endforeach</select>
<button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-outline" href="{{ route('admin.acceptances.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Reference</th><th>Job</th><th>Customer</th><th>Status</th><th>Version</th><th>Created</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr>
<td><strong>{{ $record['number'] }}</strong></td>
<td>{{ $record['project'] }} <div class="muted">Job #{{ $record['job_id'] }}</div></td>
<td>{{ $record['customer'] }}</td>
<td><span class="pill">{{ ucwords(str_replace('_',' ',$record['status'])) }}</span></td>
<td>v{{ $record['revision'] }}</td>
<td>{{ $record['created_at'] }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.acceptances.edit',$record['id']) }}">Manage</a></td></tr>
@empty<tr><td colspan="7" class="empty">No work acceptance records found.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>
@endsection
