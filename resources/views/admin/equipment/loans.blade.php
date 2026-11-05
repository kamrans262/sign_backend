@extends('admin.layout')
@section('title','Equipment Loans Management')
@section('breadcrumb','Equipment & Loans Management')
@section('heading','Equipment Loans Management')
@section('description','Track every borrower, due date, return and overdue loan without modifying asset history.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.equipment.index') }}">Equipment Assets</a>
<a class="btn btn-primary" href="{{ route('admin.equipment.loan.create') }}">Create Loan</a>
@endsection
@section('content')
<section class="panel"><div class="panel-header"><h2>Equipment Loan Ledger</h2><p class="panel-header-hint">{{ $result['total'] }} loans</p></div>
<div class="toolbar"><form class="search" method="get">
<input class="input" type="search" name="q" maxlength="255" placeholder="Borrower" value="{{ $filters['q']??'' }}">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $c)<option value="{{ $c->id }}" @selected(($filters['company_id']??'')==$c->id)>{{ $c->name }}</option>@endforeach</select>
<select class="input" name="status"><option value="">All</option>
@foreach(['active'=>'Active','returned'=>'Returned','overdue'=>'Overdue'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach
</select><button class="btn btn-primary">Filter</button><a href="{{ route('admin.equipment.loans') }}" class="btn btn-outline">Clear</a></form></div>
<div class="table-wrap"><table><thead><tr><th>Equipment</th><th>Borrower</th><th>Loan Date</th><th>Expected Return</th><th>Actual Return</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $r)<tr><td>{{ $r['equipment_code'] }} · {{ $r['equipment_name'] }}</td><td>{{ $r['borrower_name'] }}</td><td>{{ $r['loan_date'] }}</td><td>{{ $r['expected_return_date'] }}</td><td>{{ $r['actual_return_date']??'—' }}</td>
<td><span class="pill">{{ ucfirst($r['status']) }}</span></td><td><a class="btn btn-small btn-outline" href="{{ route('admin.equipment.loan.edit',$r['id']) }}">Manage</a></td></tr>
@empty<tr><td colspan="7">No matching equipment loans.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif</nav>@endif
</section>@endsection
