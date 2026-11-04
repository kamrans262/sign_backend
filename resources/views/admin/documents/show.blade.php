@extends('admin.layout')
@section('title','Document Details')
@section('breadcrumb','Document Management')
@section('heading','Document Details')
@section('description','Source, integrity and recorded history. Original approved/signed files are never overwritten.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.documents.index') }}"><x-admin.icon name="arrow-left"/> All Documents</a>
@if($document['has_pdf'])
<a class="btn btn-primary" href="{{ route('admin.documents.pdf',$document['id']) }}">Download verified PDF</a>
@endif
@endsection
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>{{ $document['title'] }}</h2><p class="panel-header-hint">{{ $document['id'] }} · {{ $company->name }}</p></div></div>
  <div class="table-wrap"><table><tbody>
    <tr><th>Reference</th><td>{{ $document['number'] }}</td><th>Source</th><td>{{ ucfirst($document['kind']) }}</td></tr>
    <tr><th>Status</th><td>{{ ucfirst($document['status']) }}</td><th>Version</th><td>v{{ $document['version'] }}</td></tr>
    <tr><th>Client</th><td>{{ $document['customer'] ?: '—' }}</td><th>Job</th><td>{{ $document['project'] ?: '—' }}</td></tr>
    <tr><th>File digest (SHA-256)</th><td colspan="3" style="overflow-wrap:anywhere">{{ $document['sha256'] ?: 'No verified PDF available' }}</td></tr>
    <tr><th>Created</th><td>{{ $document['created_at'] ?? '—' }}</td><th>Updated</th><td>{{ $document['updated_at'] ?? '—' }}</td></tr>
  </tbody></table></div>
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Versions</h2><p class="panel-header-hint">Immutable file or quote revision records</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Version</th><th>File</th><th>Recorded</th></tr></thead><tbody>
  @forelse($versions as $version)<tr>
    <td>v{{ $version['version'] }}</td>
    <td>{{ $version['original_name'] ?? 'Frozen record' }}</td>
    <td>{{ $version['created_at'] ?? '—' }}</td>
  </tr>@empty<tr><td colspan="3" class="empty">No recorded versions.</td></tr>@endforelse
  </tbody></table></div>
  @if($document['kind']==='managed' && !$m12Locked)
  <form method="post" action="{{ route('admin.documents.versions.store',$document['id']) }}" enctype="multipart/form-data" style="padding:20px">
    @csrf
    <label>Upload new PDF version (does not overwrite earlier versions)
      <input class="input" type="file" name="file" accept="application/pdf" required>
    </label>
    <button class="btn btn-primary" type="submit">Add Version</button>
  </form>
  @elseif($m12Locked)
  <p style="padding:20px">This approved report is immutable. To make corrections, create a new report.</p>
  @endif
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Attachments</h2><p class="panel-header-hint">Separate files; original PDFs remain unchanged</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Type</th><th>Size</th><th>Action</th></tr></thead><tbody>
    @forelse($attachments as $attachment)<tr>
      <td>{{ $attachment['original_name'] }}</td><td>{{ $attachment['mime_type'] }}</td>
      <td>{{ number_format($attachment['byte_size']/1024,1) }} KB</td>
      <td><a class="btn btn-small btn-outline" href="{{ route('admin.documents.attachments.download',[$document['id'],$attachment['id']]) }}">Download</a></td>
    </tr>@empty<tr><td colspan="4" class="empty">No attachments.</td></tr>@endforelse
  </tbody></table></div>
  <form method="post" action="{{ route('admin.documents.attachments.store',$document['id']) }}" enctype="multipart/form-data" style="padding:20px">
    @csrf
    <label>Attach PDF or image (10 MB max)
      <input class="input" type="file" name="file" accept="application/pdf,image/png,image/jpeg" required>
    </label>
    <button class="btn btn-primary" type="submit">Add Attachment</button>
  </form>
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Document History</h2><p class="panel-header-hint">Persisted events only; no sample activity</p></div></div>
  <div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>Date</th></tr></thead><tbody>
    @forelse($history as $event)<tr>
      <td>{{ ucfirst(str_replace('_',' ',$event['type'])) }}</td>
      <td>{{ $event['actor_name'] ?: 'System / recipient' }}</td>
      <td>{{ $event['occurred_at'] }}</td>
    </tr>@empty<tr><td colspan="3" class="empty">No recorded activity.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
