@extends('admin.layout')
@section('title','Photos & Notes')
@section('breadcrumb','Photos & Notes Management')
@section('heading','Photos & Notes Management')
@section('description','Manage private job photos, notes and approved note conversions with company and job filters.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>Job Media</h2><p class="panel-header-hint">{{ number_format($records->total()) }} records</p></div></div>
  <div class="toolbar">
    <form class="search" method="get" action="{{ route('admin.job-media.index') }}">
      <select class="input" name="kind" aria-label="Record type">
        @foreach(['photos'=>'Photos','notes'=>'Notes','conversions'=>'Converted Documents'] as $key=>$label)
        <option value="{{ $key }}" @selected($kind===$key)>{{ $label }}</option>
        @endforeach
      </select>
      <select class="input" name="company_id" aria-label="Company">
        <option value="">All Companies</option>
        @foreach($companies as $company)<option value="{{ $company->id }}" @selected((string)($filters['company_id']??'')===(string)$company->id)>{{ $company->name }}</option>@endforeach
      </select>
      <select class="input" name="job_id" aria-label="Job">
        <option value="">All Jobs</option>
        @foreach($jobs as $job)<option value="{{ $job->id }}" @selected((string)($filters['job_id']??'')===(string)$job->id)>{{ $job->reference_number }} — {{ $job->title }}</option>@endforeach
      </select>
      @if($kind!=='conversions')
        <select class="input" name="archived" aria-label="Archive status">
          <option value="0" @selected(($filters['archived']??'0')==='0')>Active</option>
          <option value="1" @selected(($filters['archived']??'0')==='1')>Archived</option>
        </select>
      @endif
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-outline" href="{{ route('admin.job-media.index') }}">Clear</a>
    </form>
  </div>
  <div class="table-wrap">
  <table><thead><tr><th>ID / Job</th><th>Company</th><th>Details</th><th>Created</th><th>Manage</th></tr></thead>
  <tbody>
  @forelse($records as $record)
  <tr>
    <td><span class="strong">#{{ $record->id }}</span><div class="muted">{{ $record->reference_number }} · {{ $record->job_title }}</div></td>
    <td>{{ $record->company_name }}</td>
    <td>
      @if($kind==='photos')<div>{{ ucfirst($record->category) }} · {{ $record->caption ?: 'No caption' }}</div><div class="muted">{{ $record->original_name }} · {{ number_format($record->byte_size/1024) }} KB</div>
      @elseif($kind==='notes')<strong>{{ $record->title }}</strong><div class="muted">{{ ucfirst(str_replace('_',' ',$record->note_type)) }} · {{ $record->visibility }}</div><div>{{ \Illuminate\Support\Str::limit($record->body,150) }}</div>
      @else <strong>{{ $record->title }}</strong><div class="muted">{{ $record->document_type }} · {{ $record->status }} @if($record->managed_document_id) · Document #{{ $record->managed_document_id }}@endif</div>
      @endif
    </td>
    <td>{{ $record->created_at }}</td>
    <td>
      @if($kind==='photos')
      <a class="btn btn-small btn-outline" href="{{ route('admin.job-media.download',['kind'=>'photos','id'=>$record->id]) }}">View / Download</a>
      @endif
      @if($kind==='notes' && $record->audio_private_path)
      <a class="btn btn-small btn-outline" href="{{ route('admin.job-media.download',['kind'=>'notes','id'=>$record->id]) }}">Audio</a>
      @endif
      @if($kind!=='conversions')
      @if(!$record->archived_at)
      <form method="post" action="{{ route('admin.job-media.update',['kind'=>$kind,'id'=>$record->id]) }}">
        @csrf @method('patch')
        @if($kind==='photos')
          <label class="hint" for="caption-{{ $record->id }}">Caption</label>
          <input class="input" id="caption-{{ $record->id }}" name="caption" value="{{ $record->caption }}" maxlength="187">
          <select class="input" name="category" aria-label="Category">
          @foreach(['before'=>'Before','during'=>'During','after'=>'After'] as $value=>$label)
          <option value="{{ $value }}" @selected($record->category===$value)>{{ $label }}</option>
          @endforeach
          </select>
        @else
          <label class="hint" for="title-{{ $record->id }}">Title</label>
          <input class="input" id="title-{{ $record->id }}" name="title" value="{{ $record->title }}" maxlength="255" required>
          <textarea class="input" name="body" maxlength="20000" rows="2">{{ $record->body }}</textarea>
          <select class="input" name="visibility" aria-label="Visibility">
          @foreach(['private'=>'Private','customer_facing'=>'Customer-facing'] as $value=>$label)
          <option value="{{ $value }}" @selected($record->visibility===$value)>{{ $label }}</option>
          @endforeach
          </select>
        @endif
        <button type="submit" class="btn btn-small btn-outline">Save Changes</button>
      </form>
      @endif
      <form method="post" action="{{ route('admin.job-media.archive',['kind'=>$kind,'id'=>$record->id]) }}">
        @csrf
        <button class="btn btn-small btn-outline" type="submit">{{ $record->archived_at ? 'Restore' : 'Archive' }}</button>
      </form>
      @endif
    </td>
  </tr>
  @empty <tr><td colspan="5" class="empty">No matching records.</td></tr>
  @endforelse
  </tbody></table>
  </div>
  @if($records->hasPages())<nav class="pagination">
    @if($records->previousPageUrl())<a class="btn btn-small" href="{{ $records->previousPageUrl() }}">Previous</a>@endif
    <span>Page {{ $records->currentPage() }} / {{ $records->lastPage() }}</span>
    @if($records->nextPageUrl())<a class="btn btn-small" href="{{ $records->nextPageUrl() }}">Next</a>@endif
  </nav>@endif
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Add Job Photo</h2></div></div>
  <form class="fields" method="post" enctype="multipart/form-data" action="{{ route('admin.job-media.photos.create') }}">
    @csrf
    <div class="field"><label>Job</label><select class="input" name="job_id" required>
      <option value="">Choose job</option>@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->reference_number }} — {{ $job->title }}</option>@endforeach
    </select></div>
    <div class="field"><label>Category</label><select class="input" name="category"><option value="before">Before</option><option value="during">During</option><option value="after">After</option></select></div>
    <div class="field"><label>Photo</label><input class="input" name="file" type="file" accept="image/jpeg,image/png" required></div>
    <div class="field"><label>Caption</label><input class="input" name="caption" maxlength="187"></div>
    <button class="btn btn-primary" type="submit">Upload Photo</button>
  </form>
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Add Job Note</h2></div></div>
  <form class="fields" method="post" action="{{ route('admin.job-media.notes.create') }}">
    @csrf
    <div class="field"><label>Job</label><select class="input" name="job_id" required>
      <option value="">Choose job</option>@foreach($jobs as $job)<option value="{{ $job->id }}">{{ $job->reference_number }} — {{ $job->title }}</option>@endforeach
    </select></div>
    <div class="field"><label>Visibility</label><select class="input" name="visibility"><option value="private">Private</option><option value="customer_facing">Customer-facing</option></select></div>
    <div class="field"><label>Title</label><input class="input" name="title" maxlength="255" required></div>
    <div class="field full"><label>Note</label><textarea class="input" name="body" maxlength="20000" rows="5" required></textarea></div>
    <button class="btn btn-primary" type="submit">Save Note</button>
  </form>
</section>
@endsection
