@extends('admin.layout')
@section('title','Subscriptions & Billing')
@section('breadcrumb','Subscriptions & Billing')
@section('heading','Subscriptions & Billing')
@section('description','Manage published plans, free document allowance, company grants and provider readiness without initiating payments.')
@section('header_actions')
<a class="btn btn-primary" href="{{ route('admin.billing.plans.create') }}">Create Plan</a>
@endsection
@section('content')
<section class="panel">
  <div class="panel-header"><h2>Billing configuration</h2><span class="pill">Stripe {{ $stripeReady?'READY':'OFF' }} · Quota enforcement {{ $settings->enforcement_enabled?'ON':'OFF' }}</span></div>
  <p>Provider mode: {{ strtoupper($stripeMode) }}. Real payment requests require separately approved private server credentials and the database activation switch. No secrets are displayed here.</p>
  <form method="post" action="{{ route('admin.billing.settings') }}">@csrf
    <div class="fields">
      <div class="field"><label for="free_document_limit">Free finalized documents per company</label>
        <input id="free_document_limit" class="input" name="free_document_limit" type="number" min="0" max="10000" required value="{{ old('free_document_limit',$settings->free_document_limit) }}"></div>
      <div class="field"><label for="trial_days">Optional calendar trial days (blank means not configured)</label>
        <input id="trial_days" class="input" name="trial_days" type="number" min="0" max="365" value="{{ old('trial_days',$settings->trial_days) }}"></div>
      <div class="field"><label for="currency">Billing currency</label>
        <select id="currency" class="input" name="currency"><option value="CHF">CHF</option></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save Trial Configuration</button></div>
  </form>
</section>
<section class="panel">
  <div class="panel-header"><h2>Plans ({{ $plans->count() }})</h2><a class="btn btn-small btn-outline" href="{{ route('admin.billing.plans.create') }}">New Plan</a></div>
  <div class="table-wrap"><table><thead><tr><th>Plan</th><th>Period</th><th>Price</th><th>Features</th><th>Published</th><th>Manage</th></tr></thead><tbody>
  @forelse($plans as $plan)
    <tr><td><strong>{{ $plan->name }}</strong><div>{{ $plan->code }} · revision {{ $plan->revision }}</div></td>
      <td>{{ ucfirst($plan->period) }}</td><td>{{ $plan->currency }} {{ number_format($plan->amount_minor/100,2) }}</td>
      <td>{{ count(json_decode($plan->features,true) ?: []) }}</td><td><span class="pill">{{ ucfirst($plan->status) }}</span></td>
      <td><a class="btn btn-small btn-outline" href="{{ route('admin.billing.plans.edit',$plan->id) }}">Manage</a></td></tr>
  @empty<tr><td colspan="6">No real plans configured. Flutter must not display demo prices as live offers.</td></tr>@endforelse
  </tbody></table></div>
</section>
<section class="panel">
  <div class="panel-header"><h2>Company subscriptions and usage</h2></div>
  <form method="get" class="fields">
    <div class="field"><label for="billing_search">Search company</label><input class="input" id="billing_search" name="q" maxlength="120" value="{{ $filters['q']??'' }}" placeholder="Company name"></div>
    <div class="field"><label for="billing_company">Company</label><select class="input" id="billing_company" name="company_id"><option value="">All companies</option>
      @foreach($companyOptions as $item)<option value="{{ $item->id }}" @selected(($filters['company_id']??null)==$item->id)>{{ $item->name }}</option>@endforeach</select></div>
    <div class="field"><label for="billing_status">Status</label><select class="input" id="billing_status" name="status"><option value="">All</option>
      @foreach(['free','trialing','active','past_due','unpaid','paused','canceled','incomplete','pending_activation'] as $state)
      <option value="{{ $state }}" @selected(($filters['status']??'')===$state)>{{ ucfirst(str_replace('_',' ',$state)) }}</option>
      @endforeach</select></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn-outline">Apply Filters</button></div>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Company</th><th>Subscription</th><th>Plan</th><th>Issued documents</th><th>Period ends</th></tr></thead><tbody>
    @forelse($companies as $company)
    <tr><td>{{ $company->name }} <small>#{{ $company->id }}</small></td>
      <td><span class="pill">{{ $company->billing_status?:'free' }}</span></td>
      <td>{{ $company->plan_name??'Not configured' }}</td>
      <td>{{ $issuanceCounts[$company->id]??0 }} / {{ $settings->free_document_limit }} included</td>
      <td>{{ $company->period_ends_at??'—' }}</td></tr>
    @empty<tr><td colspan="5">No matching companies.</td></tr>@endforelse
  </tbody></table></div>
  @if($companies->hasPages())<nav class="pagination">{{ $companies->links() }}</nav>@endif
</section>
<section class="panel">
  <div class="panel-header"><h2>Audited temporary access grants</h2></div>
  <p>Grants permit temporary company access without claiming a Stripe payment. Reason and expiry are mandatory.</p>
  <form method="post" action="{{ route('admin.billing.grants.create') }}">@csrf
    <div class="fields">
      <div class="field"><label for="grant_company">Company</label><select class="input" name="company_id" id="grant_company" required>
        <option value="">Select company</option>@foreach($companyOptions as $item)
        <option value="{{ $item->id }}" @selected(old('company_id')==$item->id)>{{ $item->name }}</option>@endforeach</select></div>
      <div class="field"><label for="grant_label">Access label</label><input class="input" id="grant_label" name="label" required maxlength="120" value="{{ old('label','Temporary access') }}"></div>
      <div class="field"><label for="grant_expiry">Expires (local time)</label><input class="input" id="grant_expiry" name="expires_at" required type="datetime-local" value="{{ old('expires_at') }}"></div>
    </div>
    <div class="field"><label for="grant_reason">Reason (audit required)</label><textarea class="input" id="grant_reason" name="reason" required minlength="10" maxlength="2000" rows="2">{{ old('reason') }}</textarea></div>
    <div class="form-actions"><button class="btn btn-primary">Grant Time-bound Access</button></div>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Company</th><th>Grant</th><th>Reason</th><th>Expiry</th><th>Action</th></tr></thead><tbody>
    @forelse($grants as $grant)<tr><td>{{ $grant->company_name }}</td><td>{{ $grant->label }}</td><td>{{ $grant->reason }}</td>
      <td>{{ $grant->expires_at }}</td><td>
        @if(\Illuminate\Support\Carbon::parse($grant->expires_at)->isFuture())
        <form method="post" action="{{ route('admin.billing.grants.revoke',$grant->id) }}">@csrf
          <button class="btn btn-small btn-outline" data-confirm="Revoke this access grant?">Revoke</button></form>
        @else <span class="pill">Expired</span> @endif
      </td></tr>
    @empty<tr><td colspan="5">No administrative grants.</td></tr>@endforelse
  </tbody></table></div>
</section>
<section class="panel">
  <div class="panel-header"><h2>Provider transactions and webhook events</h2><span class="pill">{{ $stripeReady?'Configured':'Not connected' }}</span></div>
  <p>Provider webhook events requiring reconciliation: {{ $failedWebhookCount }}. Pending unexpired Checkouts: {{ $pendingCheckoutCount }}. SaaS subscription invoices below are separate from M17 business invoices.</p>
  <div class="table-wrap"><table><thead><tr><th>Record</th><th>Company</th><th>Status</th><th>Amount / Event</th></tr></thead><tbody>
    @forelse($billingInvoices as $invoice)
      <tr><td>Subscription invoice #{{ $invoice->id }}</td><td>{{ $invoice->company_id }}</td><td>{{ $invoice->status }}</td><td>{{ $invoice->currency }} {{ number_format($invoice->amount_due_minor/100,2) }}</td></tr>
    @empty<tr><td colspan="4">No verified subscription billing invoices.</td></tr>@endforelse
    @foreach($providerEvents as $event)
      <tr><td>Webhook event #{{ $event->id }}</td><td>{{ $event->company_id??'—' }}</td><td>{{ $event->status }}</td><td>{{ $event->event_type }}</td></tr>
    @endforeach
  </tbody></table></div>
</section>
@endsection
