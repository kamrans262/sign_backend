@extends('admin.layout')
@section('title','Signature Request')
@section('breadcrumb','Digital Signatures')
@section('heading','Signature Request #'.$record->id)
@section('description','Verified request history and immutable approved-document reference.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.signatures.index') }}">Back to Requests</a>
@endsection
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>Request Details</h2><p class="panel-header-hint">Signing status comes from server records, not mobile UI actions.</p></div></div>
  <div class="table-wrap">
    <table>
      <tbody>
        <tr><th>Company</th><td>{{ $record->company?->name }}</td></tr>
        <tr><th>Quote</th><td>{{ $record->quote?->custom_quote_number ?: $record->quote?->reference_number }}</td></tr>
        <tr><th>Job</th><td>{{ $record->quote?->service_job_id ?? '—' }}</td></tr>
        <tr><th>Customer</th><td>{{ $record->signer_name }} · {{ $record->signer_email }}</td></tr>
        <tr><th>Status</th><td><span class="pill">{{ ucfirst($record->status) }}</span></td></tr>
        <tr><th>Request created</th><td>{{ $record->created_at?->format('d M Y H:i:s') }}</td></tr>
        <tr><th>Expires</th><td>{{ $record->expires_at->format('d M Y H:i:s') }}</td></tr>
        <tr><th>Viewed</th><td>{{ $record->viewed_at?->format('d M Y H:i:s') ?? 'Not confirmed' }}</td></tr>
        <tr><th>Approved revision</th><td>{{ $record->approved_revision }}</td></tr>
        <tr><th>Approved PDF SHA-256</th><td><code>{{ $record->approved_pdf_sha256 }}</code></td></tr>
        <tr><th>Final signed PDF</th><td>{{ $record->artifact ? 'Available (immutable)' : 'Not completed' }}</td></tr>
      </tbody>
    </table>
  </div>
  @if(in_array($record->status,['ready','sent','viewed']) && $record->expires_at->isFuture())
    <form method="post" action="{{ route('admin.signatures.revoke',$record->id) }}" style="padding:20px">
      @csrf
      <button class="btn btn-outline" type="submit">Revoke Signing Link</button>
    </form>
  @endif
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Audit History</h2><p class="panel-header-hint">Events are append-only and never include raw signing tokens.</p></div></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Date &amp; Time</th><th>Event</th><th>Actor</th><th>IP</th></tr></thead>
      <tbody>
        @forelse($record->events as $event)
          <tr>
            <td>{{ $event->created_at?->format('d M Y H:i:s') }}</td>
            <td>{{ str_replace('_',' ',ucfirst($event->event_type)) }}</td>
            <td>{{ $event->actor_user_id ?? 'Customer / System' }}</td>
            <td>{{ $event->ip_address ?? 'Not recorded' }}</td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No events recorded.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endsection
