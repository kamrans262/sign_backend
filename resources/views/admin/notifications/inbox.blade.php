@extends('admin.layout')
@section('title','In-app Notification Audit')
@section('breadcrumb','Notifications / In-app Audit')
@section('heading','In-app Notification Audit')
@section('description','Read-only recipient delivery and inbox history. These entries do not prove phone push delivery.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.notifications.index') }}">Notification Templates</a>
<a class="btn btn-outline" href="{{ route('admin.reminders.index') }}">Reminders</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>In-app Notifications ({{ $notifications->total() }})</h2></div>
<div class="toolbar"><form method="get" class="search">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)
<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>
@endforeach</select>
<input class="input" name="category" maxlength="30" value="{{ $filters['category']??'' }}" placeholder="Category">
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.notifications.inbox') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Category</th><th>Title / Event</th><th>Company</th><th>Recipient</th><th>Read</th><th>Dismissed</th><th>Created</th></tr></thead><tbody>
@forelse($notifications as $n)
<tr><td>{{ ucfirst($n->category) }}</td>
<td><strong>{{ $n->title }}</strong><div class="muted">{{ $n->event_key }}</div></td>
<td>{{ $n->company_name }}</td><td>{{ $n->recipient_name }}</td>
<td>{{ $n->read_at?'Read':'Unread' }}</td><td>{{ $n->dismissed_at?'Yes':'No' }}</td>
<td>{{ $n->created_at }}</td></tr>
@empty<tr><td colspan="7">No notification events recorded.</td></tr>@endforelse
</tbody></table></div><div class="pagination">{{ $notifications->links() }}</div></section>
<section class="panel" style="margin-top:20px;padding:20px">
<h2>Provider Delivery State</h2>
<p class="muted">Stage 1 only stores in-app entries. Firebase push and external delivery are disabled.</p>
@forelse($deliveryStats as $status=>$count)<p>{{ $status }}: {{ $count }}</p>
@empty<p>No external delivery attempts.</p>@endforelse
</section>
@endsection