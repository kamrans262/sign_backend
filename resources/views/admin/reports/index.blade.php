@extends('admin.layout')
@section('title','Reports Management')
@section('breadcrumb','Reports Management')
@section('heading','Reports Management')
@section('description','Real site reports and meeting minutes, with company isolation, controlled approval, PDF evidence and document history.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>Reports & Meeting Minutes</h2><p class="panel-header-hint">{{ number_format($result['total']) }} records</p></div><a href="{{ route('admin.reports.create') }}" class="btn btn-primary"><x-admin.icon name="plus"/> Create Report</a></div>
  <div class="toolbar">
    <form class="search" method="get" action="{{ route('admin.reports.index') }}">
      <input name="q" class="input" type="search" maxlength="255" placeholder="Search reports" value="{{ $filters['q']??'' }}">
      <select class="input" name="company_id" aria-label="Filter company"><option value="">All companies</option>
        @foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)($filters['company_id']??'')===(string)$company->id)>{{ $company->name }}</option>@endforeach
      </select>
      <select class="input" name="kind" aria-label="Report kind"><option value="">All types</option>
        @foreach(['site_report'=>'Site Report','meeting_minutes'=>'Meeting Minutes'] as $kind=>$label)<option value="{{ $kind }}" @selected(($filters['kind']??'')===$kind)>{{ $label }}</option>@endforeach
      </select>
      <select class="input" name="status" aria-label="Status"><option value="">All statuses</option>
        @foreach(['draft'=>'Draft','approved'=>'Approved','sent'=>'Sent','archived'=>'Archived'] as $status=>$label)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ $label }}</option>@endforeach
      </select>
      <button type="submit" class="btn btn-primary">Filter</button>
      <a class="btn btn-outline" href="{{ route('admin.reports.index') }}">Clear</a>
    </form>
  </div>
  <div class="table-wrap"><table><thead><tr><th>Reference</th><th>Company</th><th>Job / Client</th><th>Type</th><th>Status</th><th>Revision</th><th>Created</th><th>Manage</th></tr></thead><tbody>
    @forelse($result['reports'] as $report)
      <tr>
        <td><strong>{{ $report['number'] }}</strong><div class="muted">{{ $report['title'] }}</div></td>
        <td>{{ $report['company_name']??'' }}</td>
        <td>{{ $report['project']??'Job #'.$report['job_id'] }}<div class="muted">{{ $report['customer']??'' }}</div></td>
        <td>{{ $report['kind']==='meeting_minutes'?'Meeting Minutes':'Site Report' }}</td>
        <td><span class="pill">{{ ucfirst($report['status']) }}</span></td>
        <td>v{{ $report['revision'] }}</td>
        <td>{{ $report['created_at'] }}</td>
        <td><a class="btn btn-small btn-outline" href="{{ route('admin.reports.edit',$report['id']) }}">Manage</a></td>
      </tr>
    @empty <tr><td colspan="8" class="empty">No matching reports found.</td></tr>
    @endforelse
  </tbody></table></div>
  @if($result['last_page']>1)
    <nav class="pagination">@if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
    <span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
    @if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif</nav>
  @endif
</section>
@endsection
