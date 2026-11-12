@extends('admin.layout')
@section('title', 'Jobs')
@section('breadcrumb', 'Job Management')
@section('heading', 'Job Management')
@section('description', 'Manage job records and company ownership without mixing customer information.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.jobs.create') }}"><x-admin.icon name="plus" /> Add Job</a>
@endsection
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>All Jobs</h2><p class="panel-header-hint">{{ number_format($jobs->total()) }} records found</p></div></div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.jobs.index') }}" class="search" role="search">
      <input class="input" name="q" type="search" maxlength="255" value="{{ $search }}" placeholder="Search job, reference or client" aria-label="Search jobs">
      <select class="input" name="company_id" aria-label="Filter by company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" {{ $companyFilter === $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="category" aria-label="Filter by category">
        <option value="" {{ $category === '' ? 'selected' : '' }}>All Jobs</option>
        @foreach(['active'=>'Active','completed'=>'Completed','archived'=>'Archived'] as $value=>$name)
        <option value="{{ $value }}" {{ $category === $value ? 'selected' : '' }}>{{ $name }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search !== '' || $companyFilter || $category !== '')<a class="btn btn-outline" href="{{ route('admin.jobs.index') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Reference / Job</th><th>Company</th><th>Client</th><th>Stage</th><th>Due</th><th>Budget (CHF)</th><th>Progress</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($jobs as $job)
        <tr>
          <td><span class="strong">{{ $job->title }}</span><div class="muted">{{ $job->reference_number }}</div></td>
          <td>{{ $job->company?->name ?? '—' }}</td>
          <td>{{ $job->client?->company_name ?: ($job->client?->full_name ?? '—') }}</td>
          <td><span class="pill">{{ ucfirst(str_replace('_',' ', $job->category === 'archived' ? 'archived' : $job->effectiveStage())) }}</span></td>
          <td>{{ $job->due_date?->format('d M Y') ?? '—' }}</td>
          <td>{{ $job->budget_chf === null ? 'Not set' : number_format((float)$job->budget_chf,2) }}</td>
          <td>{{ $progressByJob[$job->id] ?? 0 }}%</td>
          <td>
          @if($job->category === 'archived')
            <form method="post" action="{{ route('admin.jobs.restore', $job) }}">@csrf
              <button class="btn btn-small btn-outline" type="submit">Restore</button>
            </form>
          @else
            <a class="btn btn-small btn-outline" href="{{ route('admin.jobs.edit', $job) }}">Manage <x-admin.icon name="arrow-right" /></a>
          @endif
          </td>
        </tr>
      @empty
      <tr><td colspan="8" class="empty">No matching jobs found.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($jobs->hasPages())<nav class="pagination" aria-label="Job pagination">
  @if($jobs->previousPageUrl())<a class="btn btn-small" href="{{ $jobs->previousPageUrl() }}">Previous</a>@endif
  <span>Page {{ $jobs->currentPage() }} of {{ $jobs->lastPage() }}</span>
  @if($jobs->nextPageUrl())<a class="btn btn-small" href="{{ $jobs->nextPageUrl() }}">Next</a>@endif
  </nav>@endif
</section>
@endsection
