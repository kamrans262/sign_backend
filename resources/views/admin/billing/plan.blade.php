@extends('admin.layout')
@section('title',$plan?'Edit Subscription Plan':'Create Subscription Plan')
@section('breadcrumb','Subscriptions & Billing / Plan')
@section('heading',$plan?'Manage Subscription Plan':'Create Subscription Plan')
@section('description','Prices and benefits are controlled by the Admin. No Stripe charge is created by saving or publishing a plan.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.billing.index') }}">Back to Billing</a>
@endsection
@section('content')
<form method="post" action="{{ $plan?route('admin.billing.plans.update',$plan->id):route('admin.billing.plans.store') }}">@csrf
@if($plan)@method('patch')@endif
<section class="panel">
  <div class="panel-header"><h2>Plan Details</h2>
    @if($plan)<span class="pill">{{ ucfirst($plan->status) }} · Revision {{ $plan->revision }}</span>@endif
  </div>
  @if($inUse)<p>This plan is already assigned to a subscription and cannot be edited. Create a new version instead.</p>@endif
  <div class="fields">
    <div class="field"><label for="code">Plan code</label><input class="input" id="code" name="code" required maxlength="48" pattern="[a-z0-9_-]+" value="{{ old('code',$plan->code??'') }}" @disabled($inUse)></div>
    <div class="field"><label for="name">Display name</label><input class="input" id="name" name="name" required maxlength="120" value="{{ old('name',$plan->name??'') }}" @disabled($inUse)></div>
    <div class="field"><label for="period">Billing period</label><select class="input" id="period" name="period" @disabled($inUse)>
      @foreach(['monthly'=>'Monthly','yearly'=>'Yearly'] as $value=>$label)
      <option value="{{ $value }}" @selected(old('period',$plan->period??'monthly')===$value)>{{ $label }}</option>
      @endforeach</select></div>
    <div class="field"><label for="currency">Currency</label><select class="input" id="currency" name="currency" @disabled($inUse)><option value="CHF">CHF</option></select></div>
    <div class="field"><label for="amount_minor">Price in CHF cents</label><input class="input" id="amount_minor" name="amount_minor" type="number" min="0" max="1000000000" required value="{{ old('amount_minor',$plan->amount_minor??'') }}" @disabled($inUse)></div>
    <div class="field"><label for="document_limit">Plan document limit (blank = unlimited)</label><input class="input" id="document_limit" name="document_limit" type="number" min="0" max="1000000" value="{{ old('document_limit',$plan->document_limit??'') }}" @disabled($inUse)></div>
    <div class="field"><label for="stripe_price_id">Stripe Price ID (optional; payment stays disabled)</label>
      <input class="input" id="stripe_price_id" name="stripe_price_id" maxlength="255" placeholder="price_..." value="{{ old('stripe_price_id',$plan->stripe_price_id??'') }}" @disabled($inUse)></div>
  </div>
  <div class="field"><label for="description">Description</label>
    <textarea class="input" id="description" name="description" maxlength="500" rows="3" @disabled($inUse)>{{ old('description',$plan->description??'') }}</textarea></div>
  <h3>Included features</h3>
  @php($selected=old('features',$plan?(json_decode($plan->features,true) ?: []):[]))
  <div class="fields">
    @foreach($supportedFeatures as $feature)
      <div class="field"><label><input type="checkbox" name="features[]" value="{{ $feature }}" @checked(in_array($feature,$selected,true)) @disabled($inUse)>
        {{ ucwords(preg_replace('/(?<!^)[A-Z]/',' $0',$feature)) }}</label></div>
    @endforeach
  </div>
  @unless($inUse)<div class="form-actions"><button class="btn btn-primary">{{ $plan?'Save Changes':'Create Draft Plan' }}</button></div>@endunless
</section></form>
@if($plan)
<section class="panel">
  <div class="panel-header"><h2>Publication controls</h2></div>
  <p>Publishing makes the configured plan visible in the app. Payment checkout stays disabled until Stripe setup and authorization.</p>
  @unless($inUse)
    <form method="post" action="{{ route('admin.billing.plans.status',$plan->id) }}">@csrf
    <div class="fields">
      <div class="field"><label for="status">Publication status</label>
        <select class="input" name="status" id="status">
          @foreach(['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $status=>$label)
          <option value="{{ $status }}" @selected($plan->status===$status)>{{ $label }}</option>
          @endforeach</select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn-outline">Update Status</button></div>
    </div>
    </form>
  @else <p>Plan is assigned to subscriptions and its price/revision is immutable.</p> @endunless
</section>
@endif
@endsection
