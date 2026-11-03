@extends('admin.layout')
@php($editing=isset($quote))
@php($readonly=$editing && $quote->status!=='draft')
@php($rows=old('items', $editing ? $quote->items->map(fn($item)=>[
    'title'=>$item->title,'description'=>$item->description,
    'quantity'=>$item->quantity,'dimensions'=>$item->dimensions,
    'unit_price'=>$item->unit_price_minor===null?'':number_format($item->unit_price_minor/100,2,'.',''),
  ])->all() : [['title'=>'','description'=>'','quantity'=>null,'dimensions'=>'','unit_price'=>'']]))
@section('title',$editing?'Manage Quote':'Add Quote')
@section('breadcrumb','Quote Management / '.($editing?'Manage Quote':'Add Quote'))
@section('heading',$editing?'Manage Quote':'Add Quote')
@section('description','Review actual quoted work, pricing and VAT. Approval is explicit; AI never invents prices.')
@section('header_actions')
<a class="btn btn-outline" href="{{ route('admin.quotes.index') }}"><x-admin.icon name="arrow-left" /> Back to Quotes</a>
@endsection
@section('content')
@if($editing)
<section class="panel">
<div class="panel-header"><div><h2>{{ $quote->reference_number }}</h2>
<p class="panel-header-hint">Status: {{ ucfirst($quote->status) }} · Revision {{ $quote->revision }} · Created {{ $quote->created_at?->format('d M Y') }}</p></div></div>
<div class="table-wrap"><table>
<thead><tr><th>Revision</th><th>Saved at</th><th>Approved</th><th>Items</th></tr></thead>
<tbody>
@foreach($quote->revisions()->get() as $revision)
<tr><td>{{ $revision->revision }}</td><td>{{ $revision->created_at?->format('d M Y H:i') }}</td>
<td>{{ ($revision->snapshot['status']??'')==='approved'?'Yes':'—' }}</td>
<td>{{ count($revision->snapshot['items']??[]) }}</td></tr>
@endforeach
</tbody></table></div></section>
@endif

@if(!$readonly)
<form method="post" action="{{ $editing?route('admin.quotes.update',$quote):route('admin.quotes.store') }}">
@csrf @if($editing) @method('put') @endif
<section class="panel">
<div class="panel-header"><div><h2>Quote Details</h2><p class="panel-header-hint">Only drafts can be edited. Each save creates a new auditable revision.</p></div></div>
<div class="fields">
<div class="field"><label for="company_id">Company *</label><select class="input" id="company_id" name="company_id" required>
<option value="">Select Company</option>@foreach($companies as $c)
<option value="{{ $c->id }}" {{ (string)old('company_id',$editing?$quote->company_id:'')===(string)$c->id?'selected':'' }}>{{ $c->name }}</option>@endforeach
</select></div>
<div class="field"><label for="client_id">Saved Client</label><select class="input" id="client_id" name="client_id">
<option value="">Not linked</option>@foreach($clients as $c)
<option value="{{ $c->id }}" {{ (string)old('client_id',$editing?$quote->client_id:'')===(string)$c->id?'selected':'' }}>{{ $c->full_name }} — company #{{ $c->company_id }}</option>@endforeach</select></div>
<div class="field"><label for="service_job_id">Linked Job</label><select class="input" id="service_job_id" name="service_job_id">
<option value="">No Job</option>@foreach($jobs as $j)
<option value="{{ $j->id }}" {{ (string)old('service_job_id',$editing?$quote->service_job_id:'')===(string)$j->id?'selected':'' }}>{{ $j->reference_number }} — {{ $j->title }} (company #{{ $j->company_id }})</option>@endforeach</select></div>
<div class="field"><label for="client_name">Customer Name *</label><input class="input" id="client_name" name="client_name" maxlength="255" required value="{{ old('client_name',$editing?$quote->client_name:'') }}"></div>
<div class="field"><label for="client_email">Customer Email</label><input class="input" id="client_email" name="client_email" type="email" value="{{ old('client_email',$editing?$quote->client_email:'') }}"></div>
<div class="field"><label for="client_phone">Customer Phone</label><input class="input" id="client_phone" name="client_phone" value="{{ old('client_phone',$editing?$quote->client_phone:'') }}"></div>
<div class="field full"><label for="billing_address">Billing Address</label><textarea id="billing_address" name="billing_address" rows="2" class="input">{{ old('billing_address',$editing?$quote->billing_address:'') }}</textarea></div>
<div class="field"><label for="quote_date">Quote Date *</label><input class="input" id="quote_date" name="quote_date" type="date" required value="{{ old('quote_date',$editing?$quote->quote_date?->format('Y-m-d'):today()->format('Y-m-d')) }}"></div>
<div class="field"><label for="valid_until">Valid Until</label><input class="input" id="valid_until" name="valid_until" type="date" value="{{ old('valid_until',$editing?$quote->valid_until?->format('Y-m-d'):'') }}"></div>
<div class="field"><label for="currency">Currency</label><select class="input" id="currency" name="currency">@foreach(['CHF','EUR','GBP','USD'] as $cur)
<option value="{{ $cur }}" {{ old('currency',$editing?$quote->currency:'CHF')===$cur?'selected':'' }}>{{ $cur }}</option>@endforeach</select></div>
<div class="field"><label for="vat_basis_points">VAT (basis points; 800 = 8%) *</label><input class="input" id="vat_basis_points" name="vat_basis_points" type="number" min="0" max="10000" required value="{{ old('vat_basis_points',$editing?$quote->vat_basis_points:0) }}"></div>
<div class="field"><label for="deposit_basis_points">Deposit (basis points) *</label><input class="input" id="deposit_basis_points" name="deposit_basis_points" type="number" min="0" max="10000" required value="{{ old('deposit_basis_points',$editing?$quote->deposit_basis_points:0) }}"></div>
<div class="field full"><label for="terms">Terms &amp; Conditions</label><textarea id="terms" name="terms" class="input" rows="5" maxlength="20000">{{ old('terms',$editing?$quote->terms:'') }}</textarea></div>
</div></section>

<section class="panel">
<div class="panel-header"><div><h2>Work Items</h2><p class="panel-header-hint">Prices and quantities must be entered by the professional. Unfilled lines stay draft-only.</p></div></div>
<div id="quote-items">
@foreach($rows as $i=>$line)
<div class="fields quote-item-row">
<div class="field"><label>Item {{ $i+1 }} Title *</label><input class="input" name="items[{{ $i }}][title]" maxlength="255" value="{{ $line['title']??'' }}"></div>
<div class="field"><label>Quantity</label><input class="input" name="items[{{ $i }}][quantity]" type="number" min="1" max="100000" value="{{ $line['quantity']??'' }}"></div>
<div class="field full"><label>Description</label><input class="input" name="items[{{ $i }}][description]" maxlength="5000" value="{{ $line['description']??'' }}"></div>
<div class="field"><label>Dimensions</label><input class="input" name="items[{{ $i }}][dimensions]" maxlength="255" value="{{ $line['dimensions']??'' }}"></div>
<div class="field"><label>Unit Price ({{ old('currency',$editing?$quote->currency:'CHF') }})</label><input class="input" name="items[{{ $i }}][unit_price]" placeholder="0.00" value="{{ $line['unit_price']??'' }}"></div>
</div>
@endforeach
</div>
<button class="btn btn-outline" type="button" id="add-quote-item">Add Work Item</button>
<div class="form-actions"><button class="btn btn-primary" type="submit"><x-admin.icon name="check-circle" /> {{ $editing?'Save Draft':'Create Quote' }}</button>
<a class="btn btn-outline" href="{{ route('admin.quotes.index') }}">Cancel</a></div></section>
</form>
<script>
(function(){
  const container=document.getElementById('quote-items');
  const button=document.getElementById('add-quote-item');
  button.addEventListener('click',function(){
    const n=container.children.length;
    if(n>=25){window.alert('Maximum 25 work items');return;}
    const clone=container.firstElementChild.cloneNode(true);
    clone.querySelectorAll('input').forEach(input=>{
      input.name=input.name.replace(/items\[\d+\]/,'items['+n+']');
      input.value='';
    });
    const title=clone.querySelector('label');
    if(title)title.textContent='Item '+(n+1)+' Title *';
    container.appendChild(clone);
  });
})();
</script>
@endif
@if($editing)
<section class="panel">
<div class="panel-header"><div><h2>Quote Actions</h2><p class="panel-header-hint">Approval requires an explicit administrator action. Customer delivery is a separate step.</p></div></div>
<div class="form-actions">
@if($quote->status==='draft')
<form method="post" action="{{ route('admin.quotes.approve',$quote) }}" onsubmit="return confirm('Approve this reviewed quote?')">@csrf
<button class="btn btn-primary" type="submit">Approve Quote</button></form>
@endif
@if($quote->status==='archived')
<form method="post" action="{{ route('admin.quotes.restore',$quote) }}">@csrf<button class="btn btn-outline" type="submit">Restore Quote</button></form>
@else
<form method="post" action="{{ route('admin.quotes.archive',$quote) }}" onsubmit="return confirm('Archive quote?')">@csrf @method('delete')
<button class="btn btn-danger" type="submit">Archive Quote</button></form>
@endif
</div></section>
@endif
@endsection
