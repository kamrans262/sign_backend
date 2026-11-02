@extends('admin.layout')
@section('title', 'Companies')
@section('breadcrumb', 'Companies')
@section('heading', 'Company management')
@section('header_actions')
  <a class="btn btn-primary" href="{{ route('admin.companies.create') }}">+ Add company</a>
@endsection
@section('content')
<section class="panel">
  <div class="toolbar">
    <form class="search" method="get" action="{{ route('admin.companies.index') }}" role="search">
      <input class="input" type="search" name="q" value="{{ $search }}" placeholder="Search company name or email" aria-label="Search companies">
      <select class="input" name="status" aria-label="Filter company status" style="max-width:160px">
        <option value="">All statuses</option>
        <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
        <option value="suspended" {{ $status === 'suspended' ? 'selected' : '' }}>Suspended</option>
      </select>
      <button class="btn" type="submit">Search</button>
      @if($search !== '' || $status !== '')<a class="btn" href="{{ route('admin.companies.index') }}">Clear</a>@endif
    </form>
    <span class="muted">{{ $companies->total() }} companies</span>
  </div>
  <div class="table-wrap">
    <table><thead><tr><th>Company</th><th>Owner</th><th>Members</th><th>Status</th><th>Created</th><th></th></tr></thead><tbody>
      @forelse($companies as $company)
        <tr>
          <td><div class="strong">{{ $company->name }}</div><div class="muted">{{ $company->email ?: 'No email' }}</div></td>
          <td><div>{{ $company->owner?->name ?? '—' }}</div><div class="muted">{{ $company->owner?->email }}</div></td>
          <td>{{ $company->users_count }}</td>
          <td><span class="pill {{ $company->status === 'active' ? 'green' : 'amber' }}">{{ ucfirst($company->status) }}</span></td>
          <td>{{ $company->created_at?->format('M d, Y') }}</td>
          <td><a class="btn btn-small" href="{{ route('admin.companies.edit', $company) }}">Manage</a></td>
        </tr>
      @empty<tr><td colspan="6" class="empty">No companies match the search.</td></tr>@endforelse
    </tbody></table>
  </div>
  @if($companies->hasPages())
    <div class="pagination">
      @if($companies->previousPageUrl())<a class="btn btn-small" href="{{ $companies->previousPageUrl() }}">Previous</a>@endif
      Page {{ $companies->currentPage() }} of {{ $companies->lastPage() }}
      @if($companies->nextPageUrl())<a class="btn btn-small" href="{{ $companies->nextPageUrl() }}">Next</a>@endif
    </div>
  @endif
</section>
@endsection
