@extends('admin.layout')
@section('title','Contact Message')
@section('breadcrumb','Help & Support / Contact Messages')
@section('heading','Contact Message')
@section('description',$item->reference)
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.support.contacts.index') }}">Back to Messages</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>{{ $item->subject }}</h2><span class="pill">{{ ucwords(str_replace('_',' ',$item->status)) }}</span></div>
<p><strong>Sender:</strong> {{ $item->name }} · {{ $item->email }}</p>
<p class="muted">Email supplied by visitor; not verified. Received {{ $item->created_at }}.</p>
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $item->message }}</p>
<div class="form-actions"><a class="btn btn-outline" href="mailto:{{ rawurlencode($item->email) }}">Reply by Email</a></div>
<form method="post" action="{{ route('admin.support.contacts.update',$item->id) }}">@csrf @method('patch')
<div class="field"><label for="status">Status</label><select class="input" id="status" name="status">
@foreach(['new','in_progress','resolved'] as $status)<option value="{{ $status }}" @selected($item->status===$status)>{{ ucwords(str_replace('_',' ',$status)) }}</option>@endforeach
</select></div><div class="form-actions"><button class="btn btn-primary">Save Status</button></div>
</form></section>
<section class="panel"><div class="panel-header"><h2>Delete message</h2></div>
<form method="post" action="{{ route('admin.support.contacts.destroy',$item->id) }}">@csrf @method('delete')
<div class="field"><label><input type="checkbox" name="confirm" value="1" required> Permanently delete this enquiry and its contact details.</label></div>
<div class="form-actions"><button class="btn btn-outline" type="submit">Delete Message</button></div>
</form></section>
@endsection
