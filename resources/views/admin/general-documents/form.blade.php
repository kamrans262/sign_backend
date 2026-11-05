@extends('admin.layout')
@php
$editing=isset($record);
$document=$record['document']??null;
$draft=!$editing||in_array($document['status'],['draft','reviewed'],true);
$blocks=$record['content']['blocks']??[];
$plain=implode("\n\n",array_column($blocks,'text'));
@endphp
@section('title',$editing?'Manage General Document':'Create General Document')
@section('breadcrumb','General AI Documents')
@section('heading',$editing?'General Document '.$document['number']:'Create General Document')
@section('description','Review every draft. AI suggestions never produce signed or customer-ready documents automatically.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.general-documents.index') }}">All General Documents</a>
@endsection
@section('content')
<section class="panel">
<div class="panel-header"><h2>{{ $editing?'Document v'.$document['revision']:'New Draft' }}</h2></div>
@if($draft)
<form class="form-grid" style="padding:20px" method="post" action="{{ $editing?route('admin.general-documents.update',$document['id']):route('admin.general-documents.store') }}">
@csrf @if($editing)@method('PATCH')<input type="hidden" name="lock_version" value="{{ $document['lock_version'] }}">@endif
@if(!$editing)<label>Company <select class="input" name="company_id" required><option value="">Select Company</option>@foreach($companies as $company)<option value="{{ $company->id }}" @selected(old('company_id')==$company->id)>{{ $company->name }}</option>@endforeach</select></label>@endif
<label>Document Type <select class="input" name="type">
@foreach(['letter'=>'Letter','statement'=>'Statement','power_of_attorney'=>'Power of Attorney','acceptance'=>'Acceptance','custom'=>'Custom'] as $type=>$label)<option value="{{ $type }}" @selected(old('type',$document['type']??'letter')===$type)>{{ $label }}</option>@endforeach
</select></label>
<label>Title <input class="input" name="title" maxlength="255" required value="{{ old('title',$document['title']??'') }}"></label>
<label>Language <select class="input" name="locale">@foreach(['en'=>'English','fr'=>'French','de'=>'German','it'=>'Italian'] as $lang=>$name)<option value="{{ $lang }}" @selected(old('locale',$document['locale']??'en')===$lang)>{{ $name }}</option>@endforeach</select></label>
<label>Client (optional) <select class="input" name="client_id"><option value="">None</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected(old('client_id',$document['client_id']??'')==$client->id)>{{ $client->full_name }} (#{{ $client->company_id }})</option>@endforeach</select></label>
<label>Job (optional) <select class="input" name="job_id"><option value="">None</option>@foreach($jobs as $job)<option value="{{ $job->id }}" @selected(old('job_id',$document['job_id']??'')==$job->id)>{{ $job->title }} (#{{ $job->company_id }})</option>@endforeach</select></label>
<label style="grid-column:1/-1">Document Content <textarea class="input" name="document_text" rows="13" maxlength="30000" required>{{ old('document_text',$plain) }}</textarea><small>Separate paragraphs with a blank line. M18 editor formatting is preserved through the mobile rich-text API.</small></label>
<div><button class="btn btn-primary">Save {{ $editing?'Revision':'Draft' }}</button></div></form>
@else
<div style="padding:20px">
<p>This document is {{ $document['status'] }} and immutable. The signed customer workflow and official PDF are Stage 2 actions.</p>
@foreach($blocks as $block)<p style="white-space:pre-wrap">{{ $block['text'] }}</p>@endforeach
</div>
@endif
</section>
@if($editing)
<section class="panel"><div class="panel-header"><h2>Review & Approval</h2></div><div class="toolbar">
@if($document['status']==='draft')
<form method="post" action="{{ route('admin.general-documents.transition',[$document['id'],'review']) }}">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $document['lock_version'] }}"><button class="btn btn-primary">Mark Reviewed</button></form>
@elseif($document['status']==='reviewed')
<form method="post" action="{{ route('admin.general-documents.transition',[$document['id'],'approve']) }}" onsubmit="return confirm('Approve and lock this exact document revision?')">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $document['lock_version'] }}"><button class="btn btn-primary">Approve Document</button></form>
@endif
@if(in_array($document['status'],['draft','reviewed'],true))
<form method="post" action="{{ route('admin.general-documents.transition',[$document['id'],'archive']) }}" onsubmit="return confirm('Archive this draft?')">@csrf
<input type="hidden" name="confirm" value="1"><input type="hidden" name="lock_version" value="{{ $document['lock_version'] }}"><button class="btn btn-outline">Archive</button></form>
@endif</div></section>
<section class="panel"><div class="panel-header"><h2>Private Attachments</h2></div>
@if($draft)<form class="toolbar" method="post" enctype="multipart/form-data" action="{{ route('admin.general-documents.upload',$document['id']) }}">@csrf
<input class="input" type="file" name="file" accept=".pdf,.png,.jpg,.jpeg" required><button class="btn btn-primary">Attach File</button></form>@endif
<div class="table-wrap"><table><thead><tr><th>File</th><th>SHA-256</th><th>Action</th></tr></thead><tbody>
@forelse($record['attachments'] as $a)<tr><td>{{ $a['name'] }}</td><td><code>{{ $a['sha256'] }}</code></td><td>
<a class="btn btn-small btn-outline" href="{{ route('admin.general-documents.attachment',[$document['id'],$a['id']]) }}">Download</a>
@if($draft)<form style="display:inline" method="post" action="{{ route('admin.general-documents.removeAttachment',[$document['id'],$a['id']]) }}">@csrf @method('DELETE')<button class="btn btn-small btn-outline">Remove</button></form>@endif</td></tr>
@empty<tr><td colspan="3">No attachments.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Revision History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Revision</th><th>Source</th><th>SHA-256</th><th>Created</th></tr></thead><tbody>
@foreach($record['revisions'] as $r)<tr><td>v{{ $r['revision'] }}</td><td>{{ $r['source'] }}</td><td><code>{{ $r['sha256'] }}</code></td><td>{{ $r['created_at'] }}</td></tr>@endforeach
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Audit History</h2></div>
<div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor</th><th>Time</th></tr></thead><tbody>
@foreach($record['history'] as $event)<tr><td>{{ $event['type'] }}</td><td>{{ $event['actor_user_id']??'System' }}</td><td>{{ $event['created_at'] }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
