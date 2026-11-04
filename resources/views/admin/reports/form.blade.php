@extends('admin.layout')
@section('title',isset($report)?'Edit Report':'Create Report')
@section('breadcrumb','Reports Management')
@section('heading',isset($report)?'Manage Report':'Create Report')
@section('description','All report data is linked to an actual job and company. Approved PDF originals cannot be edited.')
@section('content')
@php
  $editing=isset($report);$draft=!$editing||$report['status']==='draft';
  $content=$editing?$report['content']:[];
  $sections=$content['sections']??[];
  $lines=fn($field)=>implode("\n",$content[$field]??[]);
  $actions=implode("\n",array_map(fn($row)=>implode(' | ',[$row['description']??'',$row['assignee']??'',$row['due_date']??'']),$content['action_items']??[]));
@endphp
<section class="panel">
<div class="panel-header"><div><h2>{{ $editing?$report['number']:'New Report' }}</h2><p class="panel-header-hint">{{ $editing?ucfirst($report['status']):'Draft' }}</p></div>
  <a class="btn btn-outline" href="{{ route('admin.reports.index') }}">All Reports</a></div>
<form method="post" action="{{ $editing?route('admin.reports.update',$report['id']):route('admin.reports.store') }}" class="form-grid" style="padding:20px">
@csrf @if($editing) @method('patch') @endif
@if(!$editing)
<label>Company <select class="input" name="company_id" required>
  <option value="">Select company</option>@foreach($companies as $company)<option value="{{ $company->id }}">{{ $company->name }}</option>@endforeach
</select></label>
@endif
<label>Job <select class="input" name="job_id" required @disabled($editing||!$draft)>
  <option value="">Choose linked job</option>
  @foreach($jobs as $job)<option value="{{ $job->id }}" @selected($editing&&$report['job_id']===$job->id)>{{ $job->reference_number }} — {{ $job->title }} ({{ $job->company?->name }})</option>@endforeach
</select></label>
<label>Type <select class="input" name="kind" required @disabled($editing||!$draft)>
  <option value="site_report" @selected(!$editing||$report['kind']==='site_report')>Site Report</option>
  <option value="meeting_minutes" @selected($editing&&$report['kind']==='meeting_minutes')>Meeting Minutes</option>
</select></label>
<label>Title <input class="input" name="title" maxlength="255" required value="{{ old('title',$report['title']??'') }}" @readonly(!$draft)></label>
<label>Date <input class="input" name="report_date" type="date" required value="{{ old('report_date',isset($report)?substr($report['report_date'],0,10):date('Y-m-d')) }}" @readonly(!$draft)></label>
<label>Prepared by <input class="input" name="prepared_by" maxlength="255" required value="{{ old('prepared_by',$report['prepared_by']??'') }}" @readonly(!$draft)></label>
<label>Location <input class="input" name="location" maxlength="500" value="{{ old('location',$report['location']??'') }}" @readonly(!$draft)></label>
<label>Weather (site report) <input class="input" name="weather" maxlength="100" value="{{ old('weather',$report['weather']??'') }}" @readonly(!$draft)></label>
@foreach(['siteProgress'=>'Site Progress','notes'=>'Notes / Observations','issues'=>'Issues / Risks','materials'=>'Materials Delivery','labour'=>'Labour & Time','summary'=>'Summary','agenda'=>'Agenda'] as $field=>$label)
<label>{{ $label }} <textarea class="input" name="sections[{{ $field }}]" rows="3" maxlength="6000" @readonly(!$draft)>{{ old('sections.'.$field,$sections[$field]??'') }}</textarea></label>
@endforeach
@foreach(['attendees_lines'=>'Attendees (one per line)','absentees_lines'=>'Absentees (one per line)','decisions_lines'=>'Decisions (one per line)','next_steps_lines'=>'Next Steps (one per line)','outstanding_points_lines'=>'Outstanding Points (one per line)','action_items_lines'=>'Action Items (task | owner | YYYY-MM-DD)'] as $key=>$label)
<label>{{ $label }}<textarea class="input" name="{{ $key }}" rows="3" maxlength="12000" @readonly(!$draft)>{{ old($key,$key==='action_items_lines'?$actions:$lines(str_replace('_lines','',$key))) }}</textarea></label>
@endforeach
@if($editing && $report['kind']==='site_report')
  <fieldset style="margin:15px 0;padding:15px;border:1px solid #D9E2EA;border-radius:10px">
    <legend>Job Photos (maximum 8)</legend>
    @if($photos->isEmpty())
      <p class="muted">No photos are saved for this job. Add photos in Job Photos first.</p>
    @else
      @foreach($photos as $photo)
        <label style="display:flex;align-items:center;gap:12px;padding:6px 0">
          <input type="checkbox" name="photo_ids[]" value="{{ $photo->id }}"
             @checked(in_array((int)$photo->id,old('photo_ids',$report['photo_ids']??[])))
             @disabled(!$draft)>
          <span>Photo #{{ $photo->id }} · {{ ucfirst($photo->category) }} · {{ $photo->caption?:'No caption' }}</span>
        </label>
      @endforeach
    @endif
  </fieldset>
@endif
@if($draft)<div><button class="btn btn-primary" type="submit">Save Draft</button></div>@endif
</form>
</section>
@if($editing)
<section class="panel"><div class="panel-header"><h2>Report Lifecycle</h2></div>
<div class="toolbar">
@if($draft)
<form method="post" action="{{ route('admin.reports.approve',$report['id']) }}" onsubmit="return confirm('Approve and freeze this report as a PDF in Documents?');">
@csrf <input type="hidden" name="confirm" value="1"><input type="hidden" name="revision" value="{{ $report['revision'] }}">
<button type="submit" class="btn btn-primary">Approve PDF (v{{ $report['revision'] }})</button></form>
@else
<a class="btn btn-outline" href="{{ route('admin.reports.pdf',$report['id']) }}">Download Verified PDF</a>
<a class="btn btn-outline" href="{{ route('admin.documents.show','managed-'.$report['managed_document_id']) }}">Document & History</a>
@if(in_array($report['status'],['approved','sent'],true))
<form method="post" action="{{ route('admin.reports.send',$report['id']) }}" onsubmit="return confirm('Send the approved PDF to the linked client email?');">
@csrf <input type="hidden" name="confirm" value="1"><button class="btn btn-primary" type="submit">Send to Client</button></form>
@endif
@endif
@if($report['status']!=='archived')
<form method="post" action="{{ route('admin.reports.archive',$report['id']) }}" onsubmit="return confirm('Archive this report? Approved PDF will be preserved.');">
@csrf <button class="btn btn-outline" type="submit">Archive</button></form>
@endif
</div></section>
<section class="panel"><div class="panel-header"><h2>Audit History</h2></div><div class="table-wrap">
<table><thead><tr><th>Action</th><th>When</th><th>Actor ID</th></tr></thead><tbody>
@forelse($history as $event)<tr><td>{{ ucfirst(str_replace('_',' ',$event['type'])) }}</td><td>{{ $event['created_at'] }}</td><td>{{ $event['actor_id']??'System' }}</td></tr>
@empty <tr><td colspan="3" class="empty">No recorded events.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-header"><h2>Draft Revisions</h2></div><div class="table-wrap">
<table><thead><tr><th>Version</th><th>Saved</th><th>Actor ID</th></tr></thead><tbody>
@foreach($revisions as $revision)<tr><td>v{{ $revision['revision'] }}</td><td>{{ $revision['created_at'] }}</td><td>{{ $revision['actor_id']??'System' }}</td></tr>@endforeach
</tbody></table></div></section>
@endif
@endsection
