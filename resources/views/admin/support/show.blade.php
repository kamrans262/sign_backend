@extends('admin.layout')
@section('title','Support Ticket')
@section('breadcrumb','Help & Support / '.$item->reference)
@section('heading','Manage Support Ticket')
@section('description',$item->reference.' · '.$item->owner_email)
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.support.index') }}">Back to Tickets</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>{{ $item->subject }}</h2><span class="pill">{{ ucfirst($item->status) }}</span></div>
<p>{{ $item->description }}</p><p class="muted">Submitted by {{ $item->owner_name }} · {{ $item->owner_email }} · {{ $item->company_name ?? 'Personal support' }}</p>
<form method="post" action="{{ route('admin.support.update',$item->id) }}">@csrf @method('patch')
<div class="fields"><div class="field"><label>Status</label><select name="status" class="input">
@foreach(['open','in_progress','waiting_for_customer','resolved','closed'] as $x)<option value="{{ $x }}" @selected($item->status===$x)>{{ ucwords(str_replace('_',' ',$x)) }}</option>@endforeach
</select></div><div class="field"><label>Priority</label><select name="priority" class="input">
@foreach(['low','normal','high','urgent'] as $x)<option value="{{ $x }}" @selected($item->priority===$x)>{{ ucfirst($x) }}</option>@endforeach</select></div>
<div class="field"><label>Assigned administrator</label><select name="assigned_admin_id" class="input"><option value="">Unassigned</option>
@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($item->assigned_admin_id==$admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
</div><div class="form-actions"><button class="btn btn-primary">Save Ticket</button></div></form>
</section>
<section class="panel"><div class="panel-header"><h2>Conversation and Private Notes</h2></div>
@forelse($replies as $reply)
<div class="panel-header"><div><strong>{{ $reply->author_name }}</strong><div class="muted">{{ $reply->created_at }} @if($reply->internal)· Private staff note@endif</div>
<p>{{ $reply->body }}</p></div></div>
@empty<p class="muted">No replies yet.</p>@endforelse
<form method="post" action="{{ route('admin.support.reply',$item->id) }}">@csrf
<div class="field"><label for="body">Reply or note</label><textarea id="body" class="input" name="body" rows="5" maxlength="10000" required>{{ old('body') }}</textarea></div>
<div class="field"><label><input type="checkbox" name="internal" value="1"> Private staff note (hidden from user)</label></div>
<div class="form-actions"><button class="btn btn-primary">Save Reply</button></div>
</form></section>
<section class="panel"><div class="panel-header"><h2>Private Attachments</h2></div>
@forelse($attachments as $attachment)<p><a href="{{ route('admin.support.attachment',[$item->id,$attachment->id]) }}">{{ $attachment->original_name }}</a> · {{ $attachment->mime_type }}</p>
@empty<p class="muted">No attachments.</p>@endforelse
</section>
@endsection