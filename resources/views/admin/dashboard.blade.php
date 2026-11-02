@extends('admin.layout')
@section('title', 'Overview')
@section('breadcrumb', 'Overview')
@section('heading', 'Platform overview')
@section('header_actions')
  <a href="{{ route('admin.companies.create') }}" class="btn btn-primary">+ New company</a>
@endsection
@section('content')
<p class="subtitle" style="margin:-12px 0 24px">A current overview of registered accounts, companies and platform activities.</p>
<section class="metrics" aria-label="Platform metrics">
  <div class="metric"><div class="metric-label">Total accounts</div><div class="metric-value">{{ number_format($metrics['users']) }}</div><div class="metric-small">Registered users</div></div>
  <div class="metric"><div class="metric-label">Active accounts</div><div class="metric-value">{{ number_format($metrics['active_users']) }}</div><div class="metric-small">Permitted to access the platform</div></div>
  <div class="metric"><div class="metric-label">Companies</div><div class="metric-value">{{ number_format($metrics['companies']) }}</div><div class="metric-small">All registered businesses</div></div>
  <div class="metric"><div class="metric-label">Active companies</div><div class="metric-value">{{ number_format($metrics['active_companies']) }}</div><div class="metric-small">Available to company members</div></div>
</section>
<div class="columns">
  <section class="panel">
    <div class="toolbar"><h2>Recent companies</h2><a class="btn btn-small" href="{{ route('admin.companies.index') }}">View all</a></div>
    <div class="table-wrap"><table><thead><tr><th>Company</th><th>Owner</th><th>Status</th></tr></thead><tbody>
      @forelse($recentCompanies as $company)
        <tr><td><a class="strong" href="{{ route('admin.companies.edit', $company) }}">{{ $company->name }}</a></td><td>{{ $company->owner?->name ?? '—' }}</td><td><span class="pill {{ $company->status === 'active' ? 'green' : 'amber' }}">{{ ucfirst($company->status) }}</span></td></tr>
      @empty<tr><td colspan="3" class="empty">No companies yet.</td></tr>@endforelse
    </tbody></table></div>
  </section>
  <section class="panel">
    <div class="toolbar"><h2>Recent activity</h2><a class="btn btn-small" href="{{ route('admin.audit') }}">Audit trail</a></div>
    <div class="table-wrap"><table><thead><tr><th>Action</th><th>By</th><th>When</th></tr></thead><tbody>
      @forelse($recentLogs as $log)
        <tr><td><span class="strong">{{ $log->action }}</span></td><td>{{ $log->actor?->name ?? 'System' }}</td><td>{{ $log->created_at?->format('M d, H:i') }}</td></tr>
      @empty<tr><td colspan="3" class="empty">No activity recorded yet.</td></tr>@endforelse
    </tbody></table></div>
  </section>
</div>
@endsection
