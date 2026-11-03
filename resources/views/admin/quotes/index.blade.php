@extends('admin.layout')
@section('title','Quotes')
@section('breadcrumb','Quote Management')
@section('heading','Quote Management')
@section('description','Company-scoped quotes, revisions, approval and delivery records. No demo content.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.quotes.create') }}"><x-admin.icon name="plus" /> Add Quote</a>
@endsection
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>All Quotes</h2><p class="panel-header-hint">{{ number_format($quotes->total()) }} saved records</p></div></div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.quotes.index') }}" class="search" role="search">
      <input class="input" name="q" type="search" maxlength="255" value="{{ $search }}" placeholder="Search quote or customer" aria-label="Search quotes">
      <select class="input" name="company_id" aria-label="Filter by company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
        <option value="{{ $company->id }}" {{ $companyFilter===$company->id?'selected':'' }}>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="status" aria-label="Filter by status">
        <option value="">All Statuses</option>
        @foreach(['draft'=>'Draft','approved'=>'Approved','sent'=>'Sent','archived'=>'Archived'] as $code=>$title)
        <option value="{{ $code }}" {{ $status===$code?'selected':'' }}>{{ $title }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search!==''||$companyFilter||$status!=='')<a class="btn btn-outline" href="{{ route('admin.quotes.index') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Quote / Reference</th><th>Company</th><th>Customer</th><th>Job</th><th>Status</th><th>Total</th><th>Revision</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($quotes as $quote)
      <tr>
        <td><span class="strong">{{ $quote->reference_number }}</span><div class="muted">{{ $quote->quote_date?->format('d M Y') }}</div></td>
        <td>{{ $quote->company?->name ?? '—' }}</td>
        <td>{{ $quote->client_name ?: 'Unassigned' }}</td>
        <td>{{ $quote->service_job_id ?? '—' }}</td>
        <td><span class="pill">{{ ucfirst($quote->status) }}</span></td>
        <td>{{ $quote->total_minor===null ? 'Incomplete' : $quote->currency.' '.number_format($quote->total_minor/100,2) }}</td>
        <td>{{ $quote->revision }}</td>
        <td><a class="btn btn-small btn-outline" href="{{ route('admin.quotes.edit',$quote) }}">Manage <x-admin.icon name="arrow-right" /></a></td>
      </tr>
      @empty
      <tr><td colspan="8" class="empty">No matching quotes.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($quotes->hasPages())<nav class="pagination" aria-label="Quote pagination">
    @if($quotes->previousPageUrl())<a class="btn btn-small" href="{{ $quotes->previousPageUrl() }}">Previous</a>@endif
    <span>Page {{ $quotes->currentPage() }} of {{ $quotes->lastPage() }}</span>
    @if($quotes->nextPageUrl())<a class="btn btn-small" href="{{ $quotes->nextPageUrl() }}">Next</a>@endif
  </nav>@endif
</section>
@endsection
