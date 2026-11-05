@extends('admin.layout')
@section('title','Edit Notification Text')
@section('breadcrumb','Notifications / Templates')
@section('heading','Edit Notification Template')
@section('description','Changes to the title and message are versioned. Save Draft does not affect published notifications.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.notifications.index') }}">All Templates</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><div><h2>{{ $record->template_key }}</h2>
<p class="panel-header-hint">Channel: {{ $record->channel }} · Language: {{ strtoupper($record->locale) }} · Revision v{{ $record->draft_revision }} · {{ $record->enabled?'Enabled':'Disabled' }}</p>
</div></div>
<div style="padding:24px">
<form action="{{ route('admin.notifications.draft',$record->id) }}" method="post">
@csrf
<input type="hidden" name="draft_revision" value="{{ $record->draft_revision }}">
<div style="display:grid;gap:16px;max-width:920px">
<label>Notification Title
<input class="input" style="display:block;width:100%" id="notification-title" name="draft_title" required maxlength="180" value="{{ old('draft_title',$record->draft_title) }}">
</label>
<label>Notification Message / Body
<textarea class="input" style="display:block;width:100%;min-height:130px" id="notification-body" name="draft_body" maxlength="1000" required>{{ old('draft_body',$record->draft_body) }}</textarea>
</label>
<div><strong>Available Variables (copy into title or message)</strong>
<p class="muted">Only these variables are accepted; the list cannot be edited.</p>
@forelse($allowed as $name)<code style="margin-right:12px">&#123;{{ $name }}&#125;</code>
@empty<span class="muted">This notification has no variables.</span>@endforelse
</div>
<div class="panel" style="padding:16px">
<h3>Live Preview</h3>
<p class="muted">Example values only; no notification will be sent.</p>
<strong id="preview-title"></strong><p id="preview-body"></p>
</div>
<button class="btn btn-primary" type="submit" style="max-width:180px">Save Draft</button>
</div>
</form>
<div style="display:flex;flex-wrap:wrap;gap:12px;margin-top:20px">
<form method="post" action="{{ route('admin.notifications.publish',$record->id) }}">@csrf
<label style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="confirm" value="1" required> Confirm publish</label>
<button class="btn btn-primary" type="submit">Publish Draft</button></form>
<form method="post" action="{{ route('admin.notifications.toggle',$record->id) }}">@csrf
<input type="hidden" name="enabled" value="{{ $record->enabled?'0':'1' }}">
<button class="btn btn-outline" type="submit">{{ $record->enabled?'Disable':'Enable' }} Template</button>
</form></div>
</div></section>
<section class="panel" style="margin-top:20px">
<div class="panel-header"><h2>Revision &amp; Edit History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Revision</th><th>Action</th><th>Title</th><th>Changed By</th><th>Date</th><th>Restore</th></tr></thead><tbody>
@foreach($revisions as $revision)
<tr><td>v{{ $revision->revision }}</td><td>{{ ucfirst($revision->action) }}</td>
<td>{{ $revision->title }}</td><td>#{{ $revision->actor_user_id??'System' }}</td>
<td>{{ $revision->created_at }}</td>
<td><form method="post" action="{{ route('admin.notifications.restore',$record->id) }}">@csrf
<input type="hidden" name="revision_id" value="{{ $revision->id }}">
<button class="btn btn-small btn-outline" type="submit">Restore as Draft</button>
</form></td></tr>
@endforeach
</tbody></table></div>
</section>
<script>
(function () {
  'use strict';
  const values = @json($samples);
  const title = document.getElementById('notification-title');
  const body = document.getElementById('notification-body');
  const render = (input) => input.replace(/\{([a-z_]+)\}/g, (found, name) =>
    Object.prototype.hasOwnProperty.call(values, name) ? values[name] : found);
  const preview = () => {
    document.getElementById('preview-title').textContent = render(title.value);
    document.getElementById('preview-body').textContent = render(body.value);
  };
  title.addEventListener('input', preview); body.addEventListener('input', preview);
  preview();
})();
</script>
@endsection