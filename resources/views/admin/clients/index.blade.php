@extends('admin.layout')
@section('title', 'Clients')
@section('breadcrumb', 'Client Management')
@section('heading', 'Client Management')
@section('description', 'View, search, and manage client records while keeping each company separate.')
@section('header_actions')
  <a class="btn btn-primary" href="{{ route('admin.clients.create') }}"><x-admin.icon name="plus" /> Add Client</a>
@endsection

@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>All Clients</h2><p class="panel-header-hint">{{ number_format($clients->total()) }} records found</p></div></div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.clients.index') }}" class="search" role="search">
      <input class="input" name="q" type="search" maxlength="255" value="{{ $search }}" placeholder="Search name, company, phone or email" aria-label="Search clients">
      <select class="input" name="company_id" aria-label="Filter by company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" {{ $companyFilter === $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="status" aria-label="Filter client status">
        <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
        <option value="archived" {{ $status === 'archived' ? 'selected' : '' }}>Archived</option>
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search !== '' || $companyFilter || $status === 'archived')<a class="btn btn-outline" href="{{ route('admin.clients.index') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Client</th><th>Company</th><th>Phone</th><th>Email</th><th>Source</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($clients as $client)
        <tr>
          <td><span class="strong">{{ $client->full_name }}</span><div class="muted">{{ $client->company_name }}</div></td>
          <td>{{ $client->company?->name ?? '—' }}</td>
          <td class="muted">{{ trim(($client->dialing_code ?? '').' '.($client->phone_number ?? '')) ?: '—' }}</td>
          <td class="muted">{{ $client->email ?: '—' }}</td>
          <td><span class="pill">{{ $client->source === 'phone_contact' ? 'Phone import' : ucfirst($client->source) }}</span></td>
          <td>
          @if($client->trashed())
            <form method="post" action="{{ route('admin.clients.restore', $client) }}">@csrf
              <button class="btn btn-small btn-outline" type="submit">Restore</button>
            </form>
          @else
            <a class="btn btn-small btn-outline" href="{{ route('admin.clients.edit', $client) }}">Manage <x-admin.icon name="arrow-right" /></a>
          @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="6" class="empty">No matching client records.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($clients->hasPages())
    <nav class="pagination" aria-label="Client pagination">
      @if($clients->previousPageUrl())<a class="btn btn-small" href="{{ $clients->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $clients->currentPage() }} of {{ $clients->lastPage() }}</span>
      @if($clients->nextPageUrl())<a class="btn btn-small" href="{{ $clients->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
