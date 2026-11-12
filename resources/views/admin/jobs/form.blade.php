@extends('admin.layout')
@php($editing = isset($job))
@section('title', $editing ? 'Edit Job' : 'Add Job')
@section('breadcrumb', 'Job Management / '.($editing ? 'Edit Job' : 'Add Job'))
@section('heading', $editing ? 'Manage Job' : 'Add Job')
@section('description', 'Job dates, budget, client and team assignments must always belong to the selected company.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.jobs.index') }}"><x-admin.icon name="arrow-left" /> Back to Jobs</a>
@endsection
@section('content')
<form method="post" action="{{ $editing ? route('admin.jobs.update', $job) : route('admin.jobs.store') }}">
@csrf
@if($editing) @method('put') @endif
<section class="panel">
<div class="panel-header"><div><h2>Job Details</h2><p class="panel-header-hint">{{ $editing ? $job->reference_number : 'Create a new job' }}</p></div></div>
<div class="fields">
  <div class="field"><label for="company_id">Sign2Digital Company *</label>
    <select class="input" id="company_id" name="company_id" required>
    <option value="">Select company</option>
    @foreach($companies as $company)
      <option value="{{ $company->id }}" {{ (string)old('company_id', $editing ? $job->company_id : '') === (string)$company->id ? 'selected' : '' }}>{{ $company->name }}</option>
    @endforeach
    </select><p class="hint">Company cannot change after creation.</p>
  </div>
  <div class="field"><label for="client_id">Client *</label>
    <select class="input" id="client_id" name="client_id" required>
      <option value="">Select client</option>
      @foreach($clients as $client)
      <option value="{{ $client->id }}" {{ (string)old('client_id', $editing ? $job->client_id : '') === (string)$client->id ? 'selected' : '' }}>{{ $client->full_name }} — {{ $client->company?->name }} ({{ $client->company_name ?: 'Individual' }})</option>
      @endforeach
    </select>
  </div>
  <div class="field"><label for="title">Job Title *</label><input class="input" id="title" name="title" required maxlength="255" value="{{ old('title', $editing ? $job->title : '') }}"></div>
  <div class="field"><label for="stage">Stage *</label><select class="input" id="stage" name="stage" required>
    @foreach(['planning'=>'Planning','in_progress'=>'In progress','completed'=>'Completed'] as $value=>$label)
      <option value="{{ $value }}" {{ old('stage', $editing ? $job->stage : 'planning') === $value ? 'selected' : '' }}>{{ $label }}</option>
    @endforeach
  </select></div>
  <div class="field full"><label for="description">Description *</label>
    <textarea id="description" name="description" class="input" maxlength="15000" rows="4" required>{{ old('description', $editing ? $job->description : '') }}</textarea>
  </div>
  <div class="field full"><label for="address">Job Address</label>
    <textarea id="address" name="address" class="input" rows="2" maxlength="4000">{{ old('address', $editing ? $job->address : '') }}</textarea>
  </div>
  <div class="field"><label for="start_date">Start Date *</label>
    <input class="input" id="start_date" name="start_date" type="date" required value="{{ old('start_date', $editing ? $job->start_date?->format('Y-m-d') : '') }}">
  </div>
  <div class="field"><label for="due_date">End Date *</label>
    <input class="input" id="due_date" name="due_date" type="date" required value="{{ old('due_date', $editing ? $job->due_date?->format('Y-m-d') : '') }}">
  </div>
  <div class="field"><label for="budget_chf">Budget (CHF)</label>
    <input class="input" id="budget_chf" name="budget_chf" type="number" min="0" step="0.01" value="{{ old('budget_chf', $editing ? $job->budget_chf : '') }}">
  </div>
  <div class="field"><label>Job Progress (automatic)</label>
    <p class="hint">{{ $editing ? $jobProgress : 0 }}% — Calculated from verified job activity, not entered manually.</p>
  </div>
  <div class="field full"><label for="team_member_ids">Team Members (hold Ctrl/Cmd to select multiple)</label>
    @php($picked = old('team_member_ids', $editing ? $job->assignees->pluck('id')->all() : []))
    <select class="input" id="team_member_ids" name="team_member_ids[]" multiple size="5">
      @foreach($members as $member)
      <option value="{{ $member->id }}" {{ in_array($member->id, array_map('intval',$picked)) ? 'selected' : '' }}>{{ $member->name }} ({{ $member->email }})</option>
      @endforeach
    </select><p class="hint">Only active members of the selected company may be assigned.</p>
  </div>
</div>
<div class="form-actions">
<button class="btn btn-primary" type="submit"><x-admin.icon name="check-circle" /> {{ $editing ? 'Save Changes' : 'Create Job' }}</button>
<a class="btn btn-outline" href="{{ route('admin.jobs.index') }}">Cancel</a>
</div></section></form>
@if($editing)
<section class="panel">
  <div class="panel-header"><div><h2>Related Records</h2><p class="panel-header-hint">Quote, signed document, photo, expense, labour, deposit, report and invoice associations will appear as their persistent modules are connected. No sample records are presented as real.</p></div></div>
</section>
<section class="panel">
<div class="panel-header"><div><h2>Archive Job</h2><p class="panel-header-hint">Archiving preserves the job and its future related history.</p></div></div>
<form method="post" action="{{ route('admin.jobs.destroy', $job) }}" data-confirm="Archive this job?">
@csrf @method('delete')
<button class="btn btn-danger" type="submit">Archive Job</button>
</form></section>
@endif
@endsection
