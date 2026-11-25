@extends('admin.layout')
@section('title','Website Contact Messages')
@section('breadcrumb','Help & Support / Contact Messages')
@section('heading','Website Contact Messages')
@section('description','Review enquiries submitted through the public Contact Us page.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.support.index') }}">Support Tickets</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>Contact Messages ({{ number_format($messages->total()) }})</h2></div>
<div class="toolbar"><form method="get" action="{{ route('admin.support.contacts.index') }}" class="search">
<input class="input" name="q" maxlength="120" aria-label="Search messages" placeholder="Name, email, subject or reference" value="{{ $filters['q']??'' }}">
<select class="input" name="status" aria-label="Status"><option value="">All statuses</option>
@foreach(['new','in_progress','resolved'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ ucwords(str_replace('_',' ',$status)) }}</option>@endforeach
</select><button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.support.contacts.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Subject</th><th>Sender</th><th>Status</th><th>Received</th><th>Action</th></tr></thead><tbody>
@forelse($messages as $item)
<tr><td><strong>{{ $item->subject }}</strong></td><td>{{ $item->name }}<div class="muted">{{ $item->email }}</div></td><td><span class="pill">{{ ucwords(str_replace('_',' ',$item->status)) }}</span></td><td>{{ $item->created_at }}</td><td><a class="btn btn-small btn-outline" href="{{ route('admin.support.contacts.show',$item->id) }}">View</a></td></tr>
@empty<tr><td colspan="5">No contact messages.</td></tr>@endforelse
</tbody></table></div>
@if($messages->hasPages())<nav class="pagination">{{ $messages->links() }}</nav>@endif
</section>
@endsection
