@extends('admin.layout')
@php($editing = isset($client))
@section('title', $editing ? 'Edit Client' : 'Add Client')
@section('breadcrumb', 'Client Management / '.($editing ? 'Edit Client' : 'Add Client'))
@section('heading', $editing ? 'Manage Client' : 'Add Client')
@section('description', 'Keep all existing client fields and company ownership intact.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.clients.index') }}"><x-admin.icon name="arrow-left" /> Back to Clients</a>
@endsection

@section('content')
<form method="post" action="{{ $editing ? route('admin.clients.update', $client) : route('admin.clients.store') }}">
  @csrf
  @if($editing) @method('put') @endif
  <section class="panel">
    <div class="panel-header"><div><h2>Client Details</h2><p class="panel-header-hint">Client identity and company association</p></div></div>
    <div class="fields">
      <div class="field">
        <label for="company_id">Sign2Digital Company *</label>
        <select id="company_id" name="company_id" class="input" required>
          <option value="">Select company</option>
          @foreach($companies as $company)
            <option value="{{ $company->id }}" {{ (string) old('company_id', $editing ? $client->company_id : '') === (string) $company->id ? 'selected' : '' }}>{{ $company->name }}</option>
          @endforeach
        </select>
        @if($editing)<p class="hint">For data separation, changing the client's owning company is not allowed after creation.</p>@endif
      </div>
      <div class="field">
        <label for="full_name">Full Name *</label>
        <input id="full_name" name="full_name" class="input" required maxlength="255" value="{{ old('full_name', $editing ? $client->full_name : '') }}">
      </div>
      <div class="field">
        <label for="company_name">Client Company Name</label>
        <input id="company_name" name="company_name" class="input" maxlength="255" value="{{ old('company_name', $editing ? $client->company_name : '') }}">
      </div>
      <div class="field">
        <label for="email">E-mail</label>
        <input id="email" type="email" name="email" class="input" maxlength="255" value="{{ old('email', $editing ? $client->email : '') }}">
      </div>
      <div class="field full">
        <label for="address">Address</label>
        <textarea id="address" name="address" class="input" maxlength="4000" rows="3">{{ old('address', $editing ? $client->address : '') }}</textarea>
      </div>
      <div class="field">
        <label for="country_iso_code">Country ISO Code</label>
        <input id="country_iso_code" name="country_iso_code" class="input" maxlength="2" value="{{ old('country_iso_code', $editing ? $client->country_iso_code : 'CH') }}">
      </div>
      <div class="field">
        <label for="dialing_code">Dialing Code</label>
        <input id="dialing_code" name="dialing_code" class="input" maxlength="8" value="{{ old('dialing_code', $editing ? $client->dialing_code : '+41') }}">
      </div>
      <div class="field">
        <label for="phone_number">Phone</label>
        <input id="phone_number" name="phone_number" class="input" maxlength="50" value="{{ old('phone_number', $editing ? $client->phone_number : '') }}">
      </div>
      <div class="field full">
        <label for="notes">Additional Information</label>
        <textarea id="notes" name="notes" class="input" maxlength="15000" rows="4">{{ old('notes', $editing ? $client->notes : '') }}</textarea>
      </div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><x-admin.icon name="check-circle" /> {{ $editing ? 'Save Changes' : 'Create Client' }}</button>
      <a class="btn btn-outline" href="{{ route('admin.clients.index') }}">Cancel</a>
    </div>
  </section>
</form>
@if($editing)
<section class="panel">
  <div class="panel-header">
    <div><h2>Client History</h2><p class="panel-header-hint">Associated jobs and documents are connected through their respective future backend modules. No demo records are displayed as live history.</p></div>
  </div>
  <div class="fields">
    <div class="field"><label>Source</label><p class="muted">{{ $client->source === 'phone_contact' ? 'Imported from phone contacts' : ucfirst($client->source) }}</p></div>
    <div class="field"><label>Created</label><p class="muted">{{ $client->created_at?->format('M d, Y') }}</p></div>
  </div>
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Archive Client</h2><p class="panel-header-hint">Retains the record for audit and future job history. Archived clients cannot be selected in mobile lists.</p></div></div>
  <form method="post" action="{{ route('admin.clients.destroy', $client) }}" onsubmit="return confirm('Archive this client?')">
    @csrf @method('delete')
    <button type="submit" class="btn btn-danger">Archive Client</button>
  </form>
</section>
@endif
@endsection
