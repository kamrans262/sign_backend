@extends('admin.layout')
@section('title','Addendum '.$addendum->number)
@section('breadcrumb','Addenda Management')
@section('heading','Addendum '.$addendum->number)
@section('description','Separate addendum; original signed quote remains unchanged and traceable.')
@section('content')
<section class="panel">
  <div class="panel-header"><div><h2>{{ $addendum->title }}</h2><p class="panel-header-hint">
    {{ $addendum->company?->name }} · <span class="pill">{{ ucfirst($addendum->status) }}</span> · v{{ $addendum->revision }}
  </p></div>
    <div>
      @if($addendum->status==='draft')<a class="btn btn-outline" href="{{ route('admin.addenda.edit',$addendum->id) }}">Edit Draft</a>@endif
      @if($addendum->approved_pdf_sha256)
        <a class="btn btn-primary" href="{{ route('admin.addenda.pdf',$addendum->id) }}">{{ $addendum->status==='signed'?'Download Signed PDF':'Download Approved PDF' }}</a>
      @endif
    </div>
  </div>
  <div class="table-wrap"><table><tbody>
    <tr><th>Original signed source</th><td>signed-{{ $addendum->source_signed_artifact_id }} (original quote {{ $addendum->quote?->custom_quote_number ?: $addendum->quote?->reference_number }})</td></tr>
    <tr><th>Original SHA-256</th><td><code style="overflow-wrap:anywhere">{{ $addendum->source_pdf_sha256 }}</code></td></tr>
    <tr><th>Linked job / client</th><td>{{ $addendum->service_job_id ?: '—' }} / {{ $addendum->client_id ?: '—' }}</td></tr>
    <tr><th>Change description</th><td style="white-space:pre-wrap">{{ $addendum->change_description }}</td></tr>
    <tr><th>Additional work</th><td>{{ $addendum->additional_work ?: 'No priced work' }}</td></tr>
    <tr><th>Quantity × Unit Price</th><td>{{ $addendum->quantity ?: '—' }} × {{ $addendum->currency }} {{ $addendum->unit_price_minor===null?'—':number_format($addendum->unit_price_minor/100,2) }}</td></tr>
    <tr><th>Subtotal</th><td>{{ $addendum->currency }} {{ number_format($addendum->subtotal_minor/100,2) }}</td></tr>
    <tr><th>VAT ({{ number_format($addendum->vat_basis_points/100,2) }}%)</th><td>{{ $addendum->currency }} {{ number_format($addendum->vat_minor/100,2) }}</td></tr>
    <tr><th>Total</th><td><strong>{{ $addendum->currency }} {{ number_format($addendum->total_minor/100,2) }}</strong></td></tr>
    <tr><th>Effective date</th><td>{{ $addendum->effective_date?->format('d M Y') }}</td></tr>
    <tr><th>Approved PDF SHA-256</th><td><code style="overflow-wrap:anywhere">{{ $addendum->approved_pdf_sha256 ?: 'Not approved' }}</code></td></tr>
    <tr><th>Signed PDF SHA-256</th><td><code style="overflow-wrap:anywhere">{{ $addendum->signed_pdf_sha256 ?: 'Not signed' }}</code></td></tr>
  </tbody></table></div>
</section>

@if($addendum->status==='draft')
<section class="panel"><div class="panel-header"><div><h2>Review and Approve</h2>
  <p class="panel-header-hint">Approval freezes an independent PDF and prevents further editing. Verify all fields before approval.</p></div></div>
  <form method="post" action="{{ route('admin.addenda.approve',$addendum->id) }}" style="padding:20px">
    @csrf
    <input type="hidden" name="expected_revision" value="{{ $addendum->revision }}">
    <label><input type="checkbox" name="confirmed" value="1" required> I reviewed this addendum and approve its contents.</label>
    <div style="margin-top:14px"><button type="submit" class="btn btn-primary">Approve Addendum</button></div>
  </form>
</section>
@endif

@if(in_array($addendum->status,['approved','sent'],true))
<section class="panel"><div class="panel-header"><div><h2>Send for Signature</h2><p class="panel-header-hint">Only the verified customer can sign. Copying a link does not confirm delivery.</p></div></div>
  <form method="post" action="{{ route('admin.addenda.signature',$addendum->id) }}" class="form-grid" style="padding:20px">
    @csrf
    <label>Signer Name <input class="input" name="signer_name" maxlength="255" required value="{{ old('signer_name',$addendum->quote?->client_name) }}"></label>
    <label>Signer Email <input class="input" name="signer_email" type="email" maxlength="255" required value="{{ old('signer_email',$addendum->quote?->client_email) }}"></label>
    <label>Expiry <select class="input" name="expires_in_days">@foreach([1,2,3,5,7] as $days)<option value="{{ $days }}" @selected($days===7)>{{ $days }} days</option>@endforeach</select></label>
    <label>Delivery <select class="input" name="delivery"><option value="link">Create secure link</option><option value="email">Send email</option></select></label>
    <button type="submit" class="btn btn-primary">Request Signature</button>
  </form>
  @if(session('secure_addendum_link'))
    <div style="padding:20px"><strong>Secure link (shown once)</strong><p style="overflow-wrap:anywhere"><a href="{{ session('secure_addendum_link') }}" rel="noreferrer">{{ session('secure_addendum_link') }}</a></p></div>
  @endif
</section>
@endif

<section class="panel"><div class="panel-header"><div><h2>Signature Requests</h2></div></div>
  <div class="table-wrap"><table><thead><tr><th>Signer</th><th>Status</th><th>Expires</th><th>Sent</th><th>Action</th></tr></thead><tbody>
    @forelse($addendum->signingRequests as $request)
      <tr><td>{{ $request->signer_name }}<div class="muted">{{ $request->signer_email }}</div></td>
      <td><span class="pill">{{ ucfirst($request->status) }}</span></td>
      <td>{{ $request->expires_at?->format('d M Y H:i') }}</td>
      <td>{{ $request->sent_at?->format('d M Y H:i') ?? 'Not confirmed' }}</td>
      <td>@if(in_array($request->status,['ready','sent','viewed'],true))
        <form method="post" action="{{ route('admin.addenda.revoke',[$addendum->id,$request->id]) }}">
          @csrf <button type="submit" class="btn btn-small btn-outline">Revoke</button>
        </form>@else — @endif</td></tr>
    @empty <tr><td colspan="5" class="empty">No signature requests.</td></tr>@endforelse
  </tbody></table></div>
</section>

<section class="panel"><div class="panel-header"><div><h2>Version History</h2></div></div>
  <div class="table-wrap"><table><thead><tr><th>Version</th><th>Created</th></tr></thead>
  <tbody>@foreach($revisions as $revision)<tr><td>v{{ $revision->revision }}</td>
    <td>{{ \Illuminate\Support\Carbon::parse($revision->created_at)->format('d M Y H:i') }}</td></tr>@endforeach</tbody></table></div>
</section>
<section class="panel"><div class="panel-header"><div><h2>Audit History</h2></div></div>
  <div class="table-wrap"><table><thead><tr><th>Event</th><th>Actor ID</th><th>Timestamp</th></tr></thead>
  <tbody>@forelse($history as $event)
    <tr><td>{{ str_replace('_',' ',ucfirst($event->type)) }}</td>
    <td>{{ $event->actor_user_id ?: 'Customer / System' }}</td>
    <td>{{ \Illuminate\Support\Carbon::parse($event->occurred_at)->format('d M Y H:i:s') }}</td></tr>
    @empty <tr><td colspan="3" class="empty">No events recorded.</td></tr>@endforelse
  </tbody></table></div>
</section>
@endsection
