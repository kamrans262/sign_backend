@extends('admin.layout')
@section('title','General AI Documents')
@section('breadcrumb','Document Management')
@section('heading','General AI Documents')
@section('description','Manual and AI-assisted drafts, revision history, approval and private attachments. Nothing is sent before issuance.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.general-documents.create') }}">Create Document</a>
<a class="btn btn-outline" href="{{ route('admin.documents.index') }}">Document Catalog</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>General Document Management ({{ number_format($result['total']) }})</h2></div>
<div class="toolbar"><form method="get" action="{{ route('admin.general-documents.index') }}" class="search">
<input class="input" name="q" value="{{ $filters['q']??'' }}" maxlength="255" placeholder="Title or document reference">
<select class="input" name="company_id"><option value="">All Companies</option>
@foreach($companies as $company)<option value="{{ $company->id }}" @selected(($filters['company_id']??'')==$company->id)>{{ $company->name }}</option>@endforeach
</select>
<select class="input" name="type"><option value="">All Types</option>
@foreach(['letter'=>'Letter','statement'=>'Statement','power_of_attorney'=>'Power of Attorney','acceptance'=>'Acceptance','custom'=>'Custom'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['type']??'')===$key)>{{ $label }}</option>@endforeach
</select>
<select class="input" name="status"><option value="">All Statuses</option>
@foreach(['draft'=>'Draft','reviewed'=>'Reviewed','approved'=>'Approved','archived'=>'Archived'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>@endforeach
</select>
<button class="btn btn-primary">Filter</button><a class="btn btn-outline" href="{{ route('admin.general-documents.index') }}">Clear</a>
</form></div>
<div class="table-wrap"><table><thead><tr><th>Document</th><th>Company / Job</th><th>Type</th><th>Client</th><th>Revision</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($result['records'] as $row)
<tr><td><strong>{{ $row['title'] }}</strong><div class="muted">{{ $row['number'] }}</div></td>
<td>{{ \App\Support\AdminCompanyNames::label($row['company_id']) }}<div class="muted">{{ $row['job'] ?: 'Unlinked' }}</div></td>
<td>{{ ucwords(str_replace('_',' ',$row['type'])) }}</td><td>{{ $row['client_name'] ?: 'Unlinked' }}</td>
<td>v{{ $row['revision'] }}</td><td><span class="pill">{{ ucfirst($row['status']) }}</span></td>
<td><a class="btn btn-small btn-outline" href="{{ route('admin.general-documents.edit',$row['id']) }}">Manage</a></td></tr>
@empty<tr><td colspan="7">No general documents yet.</td></tr>@endforelse
</tbody></table></div>
@if($result['last_page']>1)<nav class="pagination"><span>Page {{ $result['page'] }} of {{ $result['last_page'] }}</span></nav>@endif
</section>
@endsection
