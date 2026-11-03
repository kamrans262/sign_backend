@extends('admin.layout')
@section('title','Digital Signatures')
@section('breadcrumb','Digital Signatures')
@section('heading','Digital Signatures')
@section('description','Track customer signature requests and verified document activity by company.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>Signature Requests</h2><p class="panel-header-hint">{{ number_format($requests->total()) }} records</p></div></div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.signatures.index') }}" class="search" role="search">
      <input class="input" name="q" type="search" maxlength="255" value="{{ $search }}" placeholder="Customer, email or quote" aria-label="Search requests">
      <select class="input" name="company_id" aria-label="Filter by company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" {{ $companyFilter===$company->id?'selected':'' }}>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="status" aria-label="Filter by status">
        <option value="">All Statuses</option>
        @foreach(['ready'=>'Ready','sent'=>'Sent','viewed'=>'Viewed','signed'=>'Signed','revoked'=>'Revoked'] as $code=>$label)
          <option value="{{ $code }}" {{ $status===$code?'selected':'' }}>{{ $label }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search!==''||$companyFilter||$status!=='')
        <a class="btn btn-outline" href="{{ route('admin.signatures.index') }}">Clear</a>
      @endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Request</th><th>Company</th><th>Customer</th><th>Quote / Job</th><th>Status</th><th>Expires</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($requests as $record)
          <tr>
            <td><span class="strong">#{{ $record->id }}</span><div class="muted">{{ $record->created_at?->format('d M Y H:i') }}</div></td>
            <td>{{ $record->company?->name ?? '—' }}</td>
            <td>{{ $record->signer_name }}<div class="muted">{{ $record->signer_email }}</div></td>
            <td>{{ $record->quote?->custom_quote_number ?: ($record->quote?->reference_number ?? '—') }}
              <div class="muted">Job: {{ $record->quote?->service_job_id ?? '—' }}</div>
            </td>
            <td><span class="pill">{{ $record->expires_at->isPast() && in_array($record->status,['ready','sent','viewed']) ? 'Expired' : ucfirst($record->status) }}</span></td>
            <td>{{ $record->expires_at->format('d M Y H:i') }}</td>
            <td><a class="btn btn-small btn-outline" href="{{ route('admin.signatures.show',$record->id) }}">Manage <x-admin.icon name="arrow-right" /></a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="empty">No signing requests match your filters.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($requests->hasPages())
    <nav class="pagination" aria-label="Request pagination">
      @if($requests->previousPageUrl())<a class="btn btn-small" href="{{ $requests->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $requests->currentPage() }} of {{ $requests->lastPage() }}</span>
      @if($requests->nextPageUrl())<a class="btn btn-small" href="{{ $requests->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
