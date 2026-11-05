@extends('admin.layout')
@section('title','Help & Support')
@section('breadcrumb','Help & Support')
@section('heading','Help & Support')
@section('description','Manage customer requests, assignment, status and published FAQs.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.support.faqs') }}">Manage FAQs</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>Support Tickets ({{ number_format($tickets->total()) }})</h2></div>
<div class="toolbar"><form method="get" action="{{ route('admin.support.index') }}" class="search">
<input class="input" name="q" maxlength="120" placeholder="Reference, subject, email" value="{{ $filters['q']??'' }}">
<select class="input" name="status"><option value="">All statuses</option>
@foreach(['open','in_progress','waiting_for_customer','resolved','closed'] as $x)
<option value="{{ $x }}" @selected(($filters['status']??'')===$x)>{{ ucwords(str_replace('_',' ',$x)) }}</option>
@endforeach</select>
<select class="input" name="priority"><option value="">All priorities</option>
@foreach(['low','normal','high','urgent'] as $x)<option value="{{ $x }}" @selected(($filters['priority']??'')===$x)>{{ ucfirst($x) }}</option>@endforeach</select>
<select class="input" name="company_id"><option value="">All companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach</select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.support.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Ticket</th><th>User</th><th>Company</th><th>Priority</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>
@forelse($tickets as $ticket)
<tr><td><strong>{{ $ticket->reference }}</strong><div class="muted">{{ $ticket->subject }}</div></td>
<td>{{ $ticket->owner_name }}<div class="muted">{{ $ticket->owner_email }}</div></td>
<td>{{ $ticket->company_name ?? 'Personal' }}</td>
<td>{{ ucfirst($ticket->priority) }}</td>
<td><span class="pill">{{ ucwords(str_replace('_',' ',$ticket->status)) }}</span></td>
<td>{{ $ticket->created_at }}</td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.support.show',$ticket->id) }}">Manage</a></td></tr>
@empty<tr><td colspan="7">No support tickets.</td></tr>@endforelse
</tbody></table></div>
@if($tickets->hasPages())<nav class="pagination">{{ $tickets->links() }}</nav>@endif
</section>
@endsection