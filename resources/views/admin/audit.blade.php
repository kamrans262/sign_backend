@extends('admin.layout')
@section('title', 'Audit Trail')
@section('breadcrumb', 'Audit Trail')
@section('heading', 'Audit Trail')
@section('description', 'Review significant administrator activity across your Sign2Digital platform.')
@section('content')
<section class="panel">
  <div class="panel-header">
    <div><h2>Administrator Actions</h2><p class="panel-header-hint">Append-only history of account, company and membership changes.</p></div>
    <span class="pill"><x-admin.icon name="shield" /> Audit Log</span>
  </div>
  <div class="toolbar">
    <form class="search" role="search" method="get" action="{{ route('admin.audit') }}">
      <input class="input" name="action" type="search" aria-label="Filter audit actions" placeholder="Search action" value="{{ $action }}">
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($action !== '')<a class="btn btn-outline" href="{{ route('admin.audit') }}">Clear</a>@endif
    </form>
    <span class="muted">{{ number_format($logs->total()) }} events</span>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Administrator</th><th scope="col">Company</th><th scope="col">Subject</th><th scope="col">IP Address</th></tr></thead>
      <tbody>
        @forelse($logs as $log)
          <tr>
            <td class="muted">{{ $log->created_at?->format('M d, Y H:i:s') }}</td>
            <td><span class="strong">{{ $log->action }}</span></td>
            <td>{{ $log->actor?->name ?? 'System' }}</td>
            <td>{{ $log->company?->name ?? '—' }}</td>
            <td class="muted">{{ $log->subject_type ? $log->subject_type.' #'.$log->subject_id : '—' }}</td>
            <td class="muted">{{ $log->ip_address ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty">No audit events found.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($logs->hasPages())
    <nav class="pagination" aria-label="Audit log pagination">
      @if($logs->previousPageUrl())<a class="btn btn-small" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
      @if($logs->nextPageUrl())<a class="btn btn-small" href="{{ $logs->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
