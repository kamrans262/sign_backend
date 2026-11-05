@extends('admin.layout')
@section('title','Notification Templates')
@section('breadcrumb','Notifications / Templates')
@section('heading','Notification Templates')
@section('description','Edit future in-app and push notification copy in English and French. Push delivery remains disabled until Firebase setup.')
@section('content')
<section class="panel">
<div class="panel-header"><h2>Notification Text Templates</h2></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.notifications.index') }}">
<input class="input" name="q" maxlength="90" value="{{ $filters['q'] ?? '' }}" placeholder="Template name or key">
<select class="input" name="channel"><option value="">All Channels</option>
@foreach(['in_app'=>'In-app','push'=>'Push','email'=>'Email'] as $key=>$label)
<option value="{{ $key }}" @selected(($filters['channel']??'')===$key)>{{ $label }}</option>
@endforeach</select>
<select class="input" name="locale"><option value="">All Languages</option><option value="en" @selected(($filters['locale']??'')==='en')>English</option><option value="fr" @selected(($filters['locale']??'')==='fr')>French</option></select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.notifications.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr>
<th>Template / Event</th><th>Channel</th><th>Language</th><th>Status</th><th>Revision</th><th>Title Preview</th><th>Manage</th>
</tr></thead><tbody>
@forelse($templates as $template)
<tr><td><strong>{{ $template->template_key }}</strong><div class="muted">{{ ucwords($template->category) }}</div></td>
<td>{{ $template->channel==='in_app'?'In-app':ucfirst($template->channel) }}</td>
<td>{{ strtoupper($template->locale) }}</td>
<td><span class="pill">{{ !$template->enabled?'Disabled':($template->published_revision_id?'Published':'Draft') }}</span></td>
<td>v{{ $template->draft_revision }}</td><td>{{ $template->draft_title }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.notifications.edit',$template->id) }}">Edit &amp; Preview</a></td></tr>
@empty<tr><td colspan="7">No notification templates configured.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $templates->links() }}</div>
</section>
@endsection