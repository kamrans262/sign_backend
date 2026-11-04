@extends('admin.layout')
@section('title',$addendum ? 'Edit Addendum' : 'Create Addendum')
@section('breadcrumb','Addenda Management')
@section('heading',$addendum ? 'Edit Addendum' : 'Create Addendum')
@section('description','Use a verified signed original. Save a separate change draft without modifying the signed quote.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>{{ $addendum ? 'Edit Draft' : 'New Addendum' }}</h2>
    <p class="panel-header-hint">All amount fields require actual user-entered values. Nothing is sent until explicitly approved.</p></div></div>
  <form method="post" action="{{ $addendum ? route('admin.addenda.update',$addendum->id) : route('admin.addenda.store') }}" class="form-grid" style="padding:20px">
    @csrf
    @if($addendum)
      @method('PUT')
      <input type="hidden" name="expected_revision" value="{{ $addendum->revision }}">
      <div><strong>Company:</strong> {{ $addendum->company?->name }}<br><strong>Original:</strong> signed-{{ $addendum->source_signed_artifact_id }}</div>
    @else
      <label>Company <select class="input" name="company_id" required>
        <option value="">Select Company</option>
        @foreach($companies as $company)
          <option value="{{ $company->id }}" @selected((string)old('company_id')===(string)$company->id)>{{ $company->name }}</option>
        @endforeach
      </select></label>
      <label>Original Signed Document <select class="input" name="source_signed_artifact_id" required>
        <option value="">Select verified signed source</option>
        @foreach($sources as $source)
          <option value="{{ $source->id }}" @selected((string)old('source_signed_artifact_id')===(string)$source->id)>
            {{ $source->request?->quote?->company?->name ?? 'Company' }} —
            {{ $source->request?->quote?->custom_quote_number ?: $source->request?->quote?->reference_number }} —
            signed-{{ $source->id }}
          </option>
        @endforeach
      </select></label>
      <p class="muted">Select a document from the same company above. The server verifies ownership and SHA-256 before saving.</p>
    @endif
    <label>Addendum Number <input class="input" name="number" maxlength="80" required value="{{ old('number',$addendum?->number??'') }}" placeholder="A-2026-0005"></label>
    <label>Title <input class="input" name="title" maxlength="255" required value="{{ old('title',$addendum?->title??'') }}"></label>
    <label>Change Description <textarea class="input" name="change_description" rows="4" maxlength="10000" required>{{ old('change_description',$addendum?->change_description??'') }}</textarea></label>
    <label>Additional Work <textarea class="input" name="additional_work" rows="2" maxlength="10000">{{ old('additional_work',$addendum?->additional_work??'') }}</textarea></label>
    <label>Quantity <input class="input" type="number" min="1" max="100000" name="quantity" value="{{ old('quantity',$addendum?->quantity??'') }}"></label>
    <label>Unit Price ({{ $addendum?->currency ?? 'original currency' }}) <input class="input" name="unit_price" inputmode="decimal" value="{{ old('unit_price',$addendum?->unit_price_minor === null ? '' : number_format($addendum->unit_price_minor/100,2,'.','')) }}" placeholder="250.00"></label>
    <label>VAT (%) <input class="input" name="vat_percent" inputmode="decimal" required value="{{ old('vat_percent',$addendum ? number_format($addendum->vat_basis_points/100,2,'.',''):'0') }}" placeholder="8.10"></label>
    <label>Effective Date <input class="input" name="effective_date" type="date" required value="{{ old('effective_date',$addendum?->effective_date?->format('Y-m-d')??'') }}"></label>
    @if($addendum)
      <p class="muted">Current server-calculated total: {{ $addendum->currency }} {{ number_format($addendum->total_minor/100,2) }}</p>
    @endif
    <div><button class="btn btn-primary" type="submit">{{ $addendum ? 'Save Changes' : 'Save Draft' }}</button>
      <a class="btn btn-outline" href="{{ $addendum ? route('admin.addenda.show',$addendum->id) : route('admin.addenda.index') }}">Cancel</a></div>
  </form>
</section>
@endsection
