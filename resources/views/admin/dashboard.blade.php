@extends('admin.layout')
@section('title', 'Overview')
@section('breadcrumb', 'Overview')
@section('heading', 'Platform Overview')
@section('description', 'Your Sign2Digital platform at a glance.')
@section('header_actions')
  <a href="{{ route('admin.users.create') }}" class="btn btn-outline"><x-admin.icon name="plus" /> Add User</a>
  <a href="{{ route('admin.companies.create') }}" class="btn btn-primary"><x-admin.icon name="plus" /> Add Company</a>
@endsection

@section('content')
@php
  // Use real database counts; never display fictitious growth or sales.
  $usersShare = $metrics['users'] > 0 ? (int) round(100 * $metrics['active_users'] / $metrics['users']) : 0;
  $companiesShare = $metrics['companies'] > 0 ? (int) round(100 * $metrics['active_companies'] / $metrics['companies']) : 0;
@endphp

<section class="hope-welcome" aria-label="Administrator welcome">
  <div class="hope-welcome-copy">
    <span class="hope-overline"><x-admin.icon name="shield" /> SIGN2DIGITAL ADMIN PANEL</span>
    <h2>Welcome back, {{ auth()->user()->name }}!</h2>
    <p>Stay on top of your companies, users and platform activity. Your dashboard is always based on real records.</p>
    <div class="hope-welcome-actions">
      <a href="{{ route('admin.companies.index') }}" class="hope-welcome-button">Manage Companies <x-admin.icon name="arrow-right" /></a>
      <a href="{{ route('admin.users.index') }}" class="hope-welcome-link">View Users <x-admin.icon name="arrow-right" /></a>
    </div>
  </div>
  <div class="hope-welcome-decor" aria-hidden="true"><span></span><span></span><span></span></div>
</section>

<section class="metrics hope-metrics" aria-label="Platform statistics">
  <a class="metric hope-metric" href="{{ route('admin.users.index') }}">
    <div class="hope-metric-top"><span class="metric-label">Total Users</span><span class="hope-stat-icon"><x-admin.icon name="users" /></span></div>
    <div class="hope-metric-bottom"><div><strong class="metric-value">{{ number_format($metrics['users']) }}</strong><span class="metric-small">Registered accounts</span></div><span class="hope-ring" style="--value:{{ $usersShare }}" aria-hidden="true"><x-admin.icon name="users" /></span></div>
  </a>
  <a class="metric hope-metric" href="{{ route('admin.users.index', ['status' => 'active']) }}">
    <div class="hope-metric-top"><span class="metric-label">Active Users</span><span class="hope-stat-icon"><x-admin.icon name="user-check" /></span></div>
    <div class="hope-metric-bottom"><div><strong class="metric-value">{{ number_format($metrics['active_users']) }}</strong><span class="metric-small">{{ $usersShare }}% of all users</span></div><span class="hope-ring" style="--value:{{ $usersShare }}" aria-hidden="true"><x-admin.icon name="user-check" /></span></div>
  </a>
  <a class="metric hope-metric" href="{{ route('admin.companies.index') }}">
    <div class="hope-metric-top"><span class="metric-label">Total Companies</span><span class="hope-stat-icon"><x-admin.icon name="building" /></span></div>
    <div class="hope-metric-bottom"><div><strong class="metric-value">{{ number_format($metrics['companies']) }}</strong><span class="metric-small">Registered businesses</span></div><span class="hope-ring" style="--value:{{ $companiesShare }}" aria-hidden="true"><x-admin.icon name="building" /></span></div>
  </a>
  <a class="metric hope-metric" href="{{ route('admin.companies.index', ['status' => 'active']) }}">
    <div class="hope-metric-top"><span class="metric-label">Active Companies</span><span class="hope-stat-icon"><x-admin.icon name="briefcase" /></span></div>
    <div class="hope-metric-bottom"><div><strong class="metric-value">{{ number_format($metrics['active_companies']) }}</strong><span class="metric-small">{{ $companiesShare }}% of all companies</span></div><span class="hope-ring" style="--value:{{ $companiesShare }}" aria-hidden="true"><x-admin.icon name="briefcase" /></span></div>
  </a>
</section>

<section class="hope-dashboard-columns" aria-label="Activity and company management">
  <div class="panel hope-data-card">
    <div class="panel-header">
      <div><h2>Recent Companies</h2><p class="panel-header-hint">Latest registered businesses</p></div>
      <a class="btn btn-small btn-outline" href="{{ route('admin.companies.index') }}">View All <x-admin.icon name="arrow-right" /></a>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Company</th><th scope="col">Owner</th><th scope="col">Status</th></tr></thead>
      <tbody>
        @forelse($recentCompanies as $company)
          <tr>
            <td><a class="hope-name-cell" href="{{ route('admin.companies.edit', $company) }}"><span class="hope-cell-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($company->name, 0, 1)) }}</span><span>{{ $company->name }}</span></a></td>
            <td>{{ $company->owner?->name ?? '—' }}</td>
            <td><span class="pill {{ $company->status === 'active' ? 'green' : 'amber' }}"><span class="pill-dot"></span>{{ ucfirst($company->status) }}</span></td>
          </tr>
        @empty
          <tr><td class="empty" colspan="3">No companies registered yet.</td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>
  <div class="panel hope-data-card">
    <div class="panel-header">
      <div><h2>Recent Activity</h2><p class="panel-header-hint">Latest administrator actions</p></div>
      <a class="btn btn-small btn-outline" href="{{ route('admin.audit') }}">Audit Trail <x-admin.icon name="arrow-right" /></a>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th scope="col">Action</th><th scope="col">By</th><th scope="col">When</th></tr></thead>
      <tbody>
        @forelse($recentLogs as $log)
          <tr><td><span class="strong">{{ $log->action }}</span></td><td>{{ $log->actor?->name ?? 'System' }}</td><td class="muted">{{ $log->created_at?->format('M d, H:i') }}</td></tr>
        @empty
          <tr><td class="empty" colspan="3">No recent activity recorded.</td></tr>
        @endforelse
      </tbody>
    </table></div>
  </div>
</section>

<section class="hope-quick-panel panel" aria-label="Quick administrative actions">
  <div class="panel-header"><div><h2>Quick Actions</h2><p class="panel-header-hint">Frequently used administration tools</p></div></div>
  <div class="hope-quick-grid">
    <a href="{{ route('admin.companies.create') }}" class="hope-quick-action"><span><x-admin.icon name="building" /></span><strong>New Company</strong><x-admin.icon name="arrow-right" /></a>
    <a href="{{ route('admin.users.create') }}" class="hope-quick-action"><span><x-admin.icon name="users" /></span><strong>New User</strong><x-admin.icon name="arrow-right" /></a>
    <a href="{{ route('admin.audit') }}" class="hope-quick-action"><span><x-admin.icon name="history" /></span><strong>View Audit History</strong><x-admin.icon name="arrow-right" /></a>
  </div>
</section>
@endsection
