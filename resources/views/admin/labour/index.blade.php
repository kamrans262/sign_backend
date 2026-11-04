@extends('admin.layout')
@section('title','Time & Labour Management')
@section('breadcrumb','Time & Labour Management')
@section('heading','Time & Labour Management')
@section('description','Verify worker timesheets, running timers, job costs and audit evidence.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.labour.create') }}">Add Labour Entry</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>Time & Labour Tracking</h2><p class="panel-header-hint">{{ number_format($result['total']) }} records · All company scopes</p></div>
<a class="btn btn-outline" href="{{ route('admin.labour.export',request()->except('page')) }}">Export CSV</a></div>
<div class="toolbar"><form class="search" action="{{ route('admin.labour.index') }}" method="get">
<input class="input" name="q" type="search" placeholder="Worker, job, reference or task" value="{{ $filters['q']??'' }}">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach
</select>
<select class="input" name="job_id"><option value="">All Jobs</option>
@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(($filters['job_id']??'')==$job->id)>{{ $job->reference_number }} · {{ $job->title }}</option>@endforeach
</select>
<select class="input" name="worker_id"><option value="">All Workers</option>
@foreach($workers as $person)<option value="{{ $person->id }}" @selected(($filters['worker_id']??'')==$person->id)>{{ $person->name }}</option>@endforeach
</select>
<select class="input" name="kind"><option value="">All Entry Types</option><option value="labour" @selected(($filters['kind']??'')==='labour')>Labour</option><option value="time_sheet" @selected(($filters['kind']??'')==='time_sheet')>Time Sheet</option></select>
<select class="input" name="status"><option value="">All Statuses</option>
@foreach(['pending'=>'Pending','approved'=>'Approved','void'=>'Void'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach
</select>
<input class="input" name="from" type="date" aria-label="From date" value="{{ $filters['from']??'' }}">
<input class="input" name="to" type="date" aria-label="To date" value="{{ $filters['to']??'' }}">
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.labour.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>ID</th><th>Job</th><th>Worker</th><th>Type</th><th>Date</th><th>Minutes</th><th>Rate CHF</th><th>Cost CHF</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $record)
<tr><td>#{{ $record['id'] }}</td><td>{{ $record['job'] }}<div class="muted">{{ $record['job_reference'] }}</div></td>
<td>{{ $record['worker_name'] }}</td><td>{{ str_replace('_',' ',$record['kind']) }}</td>
<td>{{ $record['work_date'] }}</td><td>{{ $record['duration_minutes'] }}</td>
<td>{{ $record['hourly_rate_minor']===null?'Not set':number_format($record['hourly_rate_minor']/100,2) }}</td>
<td>{{ $record['cost_minor']===null?'Not available':number_format($record['cost_minor']/100,2) }}</td>
<td><span class="pill">{{ ucfirst($record['status']) }}</span></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.labour.edit',$record['id']) }}">Manage</a></td>
</tr>
@empty<tr><td colspan="10" class="empty">No time records found.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination">
@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
<span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
@if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
</nav>@endif
</section>

<section class="panel"><div class="panel-header"><div><h2>Active Worker Timers</h2><p class="panel-header-hint">Server-authoritative, including paused timers</p></div></div>
<div class="table-wrap"><table><thead><tr><th>Company</th><th>Worker</th><th>Job</th><th>Status</th><th>Elapsed</th><th>Action</th></tr></thead><tbody>
@forelse($running as $timer)<tr>
<td>#{{ $timer['company_id'] }}</td><td>{{ $timer['worker_name'] }}</td>
<td>{{ $timer['job'] }}</td><td>{{ ucfirst($timer['status']) }}</td>
<td>{{ intdiv($timer['elapsed_seconds'],3600) }}h {{ intdiv($timer['elapsed_seconds']%3600,60) }}m</td>
<td><form action="{{ route('admin.labour.timer.stop',[$timer['company_id'],$timer['worker_id']]) }}" method="post" onsubmit="return confirm('Stop this worker timer and create a pending time entry?')">
@csrf<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<button class="btn btn-small btn-outline">Stop Timer</button></form></td></tr>
@empty<tr><td colspan="6">No running or paused timers.</td></tr>@endforelse
</tbody></table></div></section>
@endsection
