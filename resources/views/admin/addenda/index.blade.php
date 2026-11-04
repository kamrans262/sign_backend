@extends('admin.layout')
@section('title','Addenda Management')
@section('breadcrumb','Addenda Management')
@section('heading','Addenda Management')
@section('description','Separate change documents linked to verified signed originals. Signed PDFs are immutable.')
@section('content')
<section class="panel">
  <div class="panel-header">
    <div><h2>All Addenda</h2><p class="panel-header-hint">{{ number_format($addenda->total()) }} records</p></div>
    <a class="btn btn-primary" href="{{ route('admin.addenda.create') }}">Create Addendum</a>
  </div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.addenda.index') }}" role="search" class="search">
      <input class="input" type="search" name="q" maxlength="255" value="{{ $filters['q']??'' }}" placeholder="Number or title" aria-label="Search addenda">
      <select class="input" name="company_id" aria-label="Filter company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" @selected((string)($filters['company_id']??'')===(string)$company->id)>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="status" aria-label="Filter status">
        <option value="">All Statuses</option>
        @foreach(['draft'=>'Draft','approved'=>'Approved','sent'=>'Sent','signed'=>'Signed'] as $status=>$name)
          <option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ $name }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search"/> Search</button>
      <a class="btn btn-outline" href="{{ route('admin.addenda.index') }}">Clear</a>
    </form>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Number</th><th>Company</th><th>Original Quote</th><th>Title</th><th>Total</th><th>Status</th><th>Version</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($addenda as $item)
      <tr>
        <td><span class="strong">{{ $item->number }}</span><div class="muted">{{ $item->created_at?->format('d M Y') }}</div></td>
        <td>{{ $item->company?->name }}</td>
        <td>{{ $item->quote?->custom_quote_number ?: $item->quote?->reference_number }}</td>
        <td>{{ $item->title }}</td>
        <td>{{ $item->currency }} {{ number_format($item->total_minor/100,2) }}</td>
        <td><span class="pill">{{ ucfirst($item->status) }}</span></td>
        <td>v{{ $item->revision }}</td>
        <td><a class="btn btn-small btn-outline" href="{{ route('admin.addenda.show',$item->id) }}">Manage <x-admin.icon name="arrow-right"/></a></td>
      </tr>
      @empty
      <tr><td colspan="8" class="empty">No addenda match your filters.</td></tr>
      @endforelse
    </tbody>
  </table></div>
  @if($addenda->hasPages())
    <div class="toolbar">{{ $addenda->links() }}</div>
  @endif
</section>
@endsection
