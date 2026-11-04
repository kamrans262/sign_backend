@extends('admin.layout')
@section('title','Documents')
@section('breadcrumb','Document Management')
@section('heading','Document Management')
@section('description','Company documents, frozen quotes, signed artifacts, versions and attachments. All records are real and tenant-linked.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>All Documents</h2><p class="panel-header-hint">{{ number_format($result['total']) }} records</p></div></div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.documents.index') }}" class="search" role="search">
      <input class="input" type="search" name="q" maxlength="255" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, customer or job" aria-label="Search documents">
      <select class="input" name="company_id" aria-label="Filter company">
        <option value="">All Companies</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" @selected((string)($filters['company_id']??'')===(string)$company->id)>{{ $company->name }}</option>
        @endforeach
      </select>
      <select class="input" name="kind" aria-label="Filter type">
        <option value="">All Sources</option>
        @foreach(['quote'=>'Quotes','signed'=>'Signed Quotes','managed'=>'Uploaded Files'] as $code=>$label)
          <option value="{{ $code }}" @selected(($filters['kind']??'')===$code)>{{ $label }}</option>
        @endforeach
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search"/> Search</button>
      <a class="btn btn-outline" href="{{ route('admin.documents.index') }}">Clear</a>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Document</th><th>Company</th><th>Source</th><th>Customer / Job</th><th>Status</th><th>Version</th><th>Created</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($result['documents'] as $document)
        <tr>
          <td><span class="strong">{{ $document['title'] }}</span><div class="muted">{{ $document['number'] }}</div></td>
          <td>{{ $companyNames[$document['company_id']] ?? 'Unknown company' }}</td>
          <td>{{ ucfirst($document['kind']) }}</td>
          <td>{{ $document['customer'] ?: '—' }}<div class="muted">{{ $document['project'] ?: 'No linked job' }}</div></td>
          <td><span class="pill">{{ ucfirst($document['status']) }}</span></td>
          <td>v{{ $document['version'] }}</td>
          <td>{{ $document['created_at'] ? \Illuminate\Support\Carbon::parse($document['created_at'])->format('d M Y') : '—' }}</td>
          <td><a class="btn btn-small btn-outline" href="{{ route('admin.documents.show',$document['id']) }}">Manage <x-admin.icon name="arrow-right"/></a></td>
        </tr>
      @empty
        <tr><td colspan="8" class="empty">No documents match your filters.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  @if($result['last_page']>1)
  <nav class="pagination" aria-label="Document pages">
    @if($result['page']>1)<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']-1]) }}">Previous</a>@endif
    <span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span>
    @if($result['page']<$result['last_page'])<a class="btn btn-small" href="{{ request()->fullUrlWithQuery(['page'=>$result['page']+1]) }}">Next</a>@endif
  </nav>
  @endif
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Upload Document</h2><p class="panel-header-hint">PDF files are private. Signed artifacts cannot be replaced.</p></div></div>
  <form method="post" action="{{ route('admin.documents.store') }}" enctype="multipart/form-data" class="form-grid" style="padding:20px">
    @csrf
    <label>Company <select class="input" name="company_id" required>
      <option value="">Select company</option>
      @foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach
    </select></label>
    <label>Category <select class="input" name="category" required>
      @foreach(['reports'=>'Report','addends'=>'Addendum','receipts'=>'Receipt','invoices'=>'Invoice','other'=>'Other','contacts'=>'Contact'] as $code=>$title)
        <option value="{{ $code }}">{{ $title }}</option>
      @endforeach
    </select></label>
    <label>Title <input class="input" type="text" name="title" maxlength="255" required></label>
    <label>Reference <input class="input" type="text" name="number" maxlength="80"></label>
    <label>Client ID (optional) <input class="input" type="number" min="1" name="client_id"></label>
    <label>Job ID (optional) <input class="input" type="number" min="1" name="job_id"></label>
    <label>PDF (10 MB max) <input class="input" type="file" accept="application/pdf" name="file" required></label>
    <div><button class="btn btn-primary" type="submit"><x-admin.icon name="plus"/> Upload PDF</button></div>
  </form>
</section>
@endsection
