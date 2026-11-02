@extends('admin.layout')
@section('title', 'Companies')
@section('breadcrumb', 'Companies')
@section('heading', 'Company Management')
@section('description', 'Manage company profiles, ownership, access, and active business status.')
@section('header_actions')
  <a class="btn btn-primary" href="{{ route('admin.companies.create') }}"><x-admin.icon name="plus" /> Add Company</a>
@endsection

@section('content')
<section class="panel">
  <div class="panel-header">
    <div><h2>All Companies</h2><p class="panel-header-hint">{{ number_format($companies->total()) }} {{ $companies->total() === 1 ? 'company' : 'companies' }} found</p></div>
  </div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.companies.index') }}" class="search" role="search">
      <input class="input" type="search" name="q" value="{{ $search }}" placeholder="Search company or email" aria-label="Search companies">
      <select class="input" name="status" aria-label="Filter company status">
        <option value="">All Statuses</option>
        <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
        <option value="suspended" {{ $status === 'suspended' ? 'selected' : '' }}>Suspended</option>
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search !== '' || $status !== '')<a class="btn btn-outline" href="{{ route('admin.companies.index') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Company</th><th scope="col">Owner</th><th scope="col">Members</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col">Action</th></tr></thead>
      <tbody>
        @forelse($companies as $company)
          <tr>
            <td><div class="strong">{{ $company->name }}</div><div class="muted">{{ $company->email ?: 'No email' }}</div></td>
            <td><div class="strong">{{ $company->owner?->name ?? '—' }}</div><div class="muted">{{ $company->owner?->email }}</div></td>
            <td>{{ $company->users_count }}</td>
            <td><span class="pill {{ $company->status === 'active' ? 'green' : 'amber' }}"><span class="pill-dot"></span> {{ ucfirst($company->status) }}</span></td>
            <td class="muted">{{ $company->created_at?->format('M d, Y') }}</td>
            <td><a class="btn btn-small btn-outline" href="{{ route('admin.companies.edit', $company) }}">Manage <x-admin.icon name="arrow-right" /></a></td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty">No matching companies. Try another search or add a company.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($companies->hasPages())
    <nav class="pagination" aria-label="Company list pagination">
      @if($companies->previousPageUrl())<a class="btn btn-small" href="{{ $companies->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $companies->currentPage() }} of {{ $companies->lastPage() }}</span>
      @if($companies->nextPageUrl())<a class="btn btn-small" href="{{ $companies->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
