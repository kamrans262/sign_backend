@extends('admin.layout')
@section('title', 'Audit Trail')
@section('breadcrumb', 'Audit Trail')
@section('heading', 'Audit trail')
@section('content')
<section class="panel">
  <h2>Recorded administrator actions</h2><p class="panel-subtitle">This history is append-only. Changes to administrators, companies and company memberships are recorded.</p>
  <div class="toolbar">
    <form class="search" role="search" method="get" action="{{ route('admin.audit') }}">
      <input class="input" name="action" type="search" aria-label="Filter audit actions" placeholder="Filter action name" value="{{ $action }}">
      <button class="btn" type="submit">Filter</button>
      @if($action !== '')<a class="btn" href="{{ route('admin.audit') }}">Clear</a>@endif
    </form>
    <span class="muted">{{ $logs->total() }} events</span>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Time (server)</th><th>Action</th><th>Actor</th><th>Company</th><th>Subject</th><th>IP</th></tr></thead>
    <tbody>
      @forelse($logs as $log)
        <tr>
          <td>{{ $log->created_at?->format('M d, Y H:i:s') }}</td>
          <td><span class="strong">{{ $log->action }}</span></td>
          <td>{{ $log->actor?->name ?? 'System' }}</td>
          <td>{{ $log->company?->name ?? '—' }}</td>
          <td>{{ $log->subject_type ? $log->subject_type.' #'.$log->subject_id : '—' }}</td>
          <td>{{ $log->ip_address ?? '—' }}</td>
        </tr>
      @empty<tr><td colspan="6" class="empty">No audit events found.</td></tr>@endforelse
    </tbody>
  </table></div>
  @if($logs->hasPages())
    <div class="pagination">
      @if($logs->previousPageUrl())<a class="btn btn-small" href="{{ $logs->previousPageUrl() }}">Previous</a>@endif
      Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}
      @if($logs->nextPageUrl())<a class="btn btn-small" href="{{ $logs->nextPageUrl() }}">Next</a>@endif
    </div>
  @endif
</section>
@endsection
