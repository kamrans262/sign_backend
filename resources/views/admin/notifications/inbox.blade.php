@extends('admin.layout')
@section('title','In-app Notification Audit')
@section('breadcrumb','Notifications / In-app Audit')
@section('heading','In-app Notification Audit')
@section('description','Read-only in-app and push delivery history. Provider submission does not prove display on the phone.')
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
<p class="muted">External push requires the server Firebase feature gate, a configured private service account and the recipient's explicit opt-in. Provider submission is not confirmed phone delivery.</p>
@forelse($deliveryStats as $status=>$count)<p>{{ $status }}: {{ $count }}</p>
@empty<p>No external delivery attempts.</p>@endforelse
</section>
@endsection
<section class="panel" style="margin-top:20px">
<div class="panel-header"><h2>Firebase Push Delivery Audit ({{ $pushDeliveries->total() }})</h2></div>
<div class="toolbar"><form method="get" class="search" action="{{ route('admin.notifications.inbox') }}">
<select class="input" name="company_id"><option value="">All companies</option>
@foreach($companies as $company)
<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>
@endforeach</select>
<select class="input" name="delivery_status"><option value="">All statuses</option>
@foreach(['pending'=>'Pending','retry'=>'Retry pending','sending'=>'In flight','submitted'=>'Accepted by provider','failed'=>'Failed','unknown'=>'Unknown - inspect','invalid'=>'Invalid device','revoked'=>'Cancelled'] as $key=>$label)
<option value="{{ $key }}" @selected(($filters['delivery_status']??'')===$key)>{{ $label }}</option>
@endforeach</select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.notifications.inbox') }}">Clear</a>
</form></div>
<p class="muted" style="padding:0 20px">Provider accepted means Firebase accepted the request; it does not mean the device received, displayed or read it. Unknown outcomes are not automatically retried.</p>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Company / Recipient</th><th>Platform</th><th>Delivery</th><th>Attempts</th><th>Revision</th><th>Provider reference / Error</th><th>Attempted</th></tr></thead><tbody>
@forelse($pushDeliveries as $push)
<tr><td><strong>{{ $push->event_key }}</strong><div class="muted">{{ $push->category }}</div></td>
<td>{{ $push->company_name }}<div class="muted">{{ $push->recipient_name }}</div></td>
<td>{{ strtoupper($push->platform) }}{{ !$push->device_active?' (revoked)':'' }}</td>
<td><span class="pill">{{ $push->status==='submitted'?'Accepted by provider':ucfirst($push->status) }}</span></td>
<td>{{ $push->attempts }}</td><td>{{ $push->template_revision_id ?? '—' }}</td>
<td>{{ $push->provider_reference ?: ($push->failure_code ?: '—') }}</td>
<td>{{ $push->attempted_at ?: 'Not sent' }}</td></tr>
@empty<tr><td colspan="8">No Firebase delivery records.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $pushDeliveries->links() }}</div>
<div style="padding:12px 20px" class="muted">Summary:
@forelse($pushStats as $status=>$count)
<strong>{{ $status }}</strong>: {{ $count }}@if(!$loop->last) · @endif
@empty No push attempts yet.
@endforelse
</div>
</section>
