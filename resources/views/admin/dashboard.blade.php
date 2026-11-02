@extends('admin.layout')
@section('title', 'Overview')
@section('breadcrumb', 'Overview')
@section('heading', 'Platform Overview')
@section('description', 'Manage your Sign2Digital businesses and accounts from one place.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.users.create') }}"><x-admin.icon name="plus" /> Add User</a>
  <a class="btn btn-primary" href="{{ route('admin.companies.create') }}"><x-admin.icon name="plus" /> Add Company</a>
@endsection

@section('content')
<section class="metrics" aria-label="Platform statistics">
  <a class="metric" href="{{ route('admin.users.index') }}">
    <span class="metric-icon"><x-admin.icon name="users" /></span>
    <span class="metric-label">Total Users</span>
    <span class="metric-value">{{ number_format($metrics['users']) }}</span>
    <span class="metric-small">Registered accounts</span>
  </a>
  <a class="metric" href="{{ route('admin.users.index', ['status' => 'active']) }}">
    <span class="metric-icon"><x-admin.icon name="user-check" /></span>
    <span class="metric-label">Active Users</span>
    <span class="metric-value">{{ number_format($metrics['active_users']) }}</span>
    <span class="metric-small">Currently enabled accounts</span>
  </a>
  <a class="metric" href="{{ route('admin.companies.index') }}">
    <span class="metric-icon"><x-admin.icon name="building" /></span>
    <span class="metric-label">Total Companies</span>
    <span class="metric-value">{{ number_format($metrics['companies']) }}</span>
    <span class="metric-small">Registered businesses</span>
  </a>
  <a class="metric" href="{{ route('admin.companies.index', ['status' => 'active']) }}">
    <span class="metric-icon"><x-admin.icon name="briefcase" /></span>
    <span class="metric-label">Active Companies</span>
    <span class="metric-value">{{ number_format($metrics['active_companies']) }}</span>
    <span class="metric-small">Businesses in good standing</span>
  </a>
</section>

<div class="columns">
  <section class="panel">
    <div class="panel-header">
      <div><h2>Recent Companies</h2><p class="panel-header-hint">Latest businesses added to Sign2Digital</p></div>
      <a href="{{ route('admin.companies.index') }}" class="btn btn-small btn-outline">View All <x-admin.icon name="arrow-right" /></a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Company</th><th scope="col">Owner</th><th scope="col">Status</th></tr></thead>
        <tbody>
          @forelse($recentCompanies as $company)
            <tr>
              <td><a class="strong" href="{{ route('admin.companies.edit', $company) }}">{{ $company->name }}</a></td>
              <td>{{ $company->owner?->name ?? '—' }}</td>
              <td><span class="pill {{ $company->status === 'active' ? 'green' : 'amber' }}"><span class="pill-dot"></span> {{ ucfirst($company->status) }}</span></td>
            </tr>
          @empty
            <tr><td colspan="3" class="empty">No companies registered yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div><h2>Recent Activity</h2><p class="panel-header-hint">Verified administrator actions</p></div>
      <a href="{{ route('admin.audit') }}" class="btn btn-small btn-outline">View History <x-admin.icon name="arrow-right" /></a>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th scope="col">Action</th><th scope="col">By</th><th scope="col">Date</th></tr></thead>
        <tbody>
          @forelse($recentLogs as $log)
            <tr>
              <td><span class="strong">{{ $log->action }}</span></td>
              <td>{{ $log->actor?->name ?? 'System' }}</td>
              <td class="muted">{{ $log->created_at?->format('M d, H:i') }}</td>
            </tr>
          @empty
            <tr><td colspan="3" class="empty">No recent activity yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
