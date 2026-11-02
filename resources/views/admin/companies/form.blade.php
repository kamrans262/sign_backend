@extends('admin.layout')
@php($editing = isset($company))
@section('title', $editing ? 'Edit Company' : 'New Company')
@section('breadcrumb', 'Companies / '.($editing ? 'Edit' : 'New'))
@section('heading', $editing ? 'Manage company' : 'Create a company')
@section('header_actions')
  <a class="btn" href="{{ route('admin.companies.index') }}">All companies</a>
@endsection
@section('content')
<form method="post" action="{{ $editing ? route('admin.companies.update', $company) : route('admin.companies.store') }}">
  @csrf
  @if($editing)@method('put')@endif
  <section class="panel">
    <h2>Company information</h2><p class="panel-subtitle">Company information is isolated from every other company. Branding file uploads will be connected in the company module.</p>
    <div class="fields">
      <div class="field full"><label for="name">Company name *</label><input class="input" id="name" name="name" required maxlength="255" value="{{ old('name', $editing ? $company->name : '') }}"></div>
      <div class="field"><label for="owner_user_id">Company owner *</label>
        <select class="input" id="owner_user_id" name="owner_user_id" required>
          <option value="">Choose an active user</option>
          @foreach($availableUsers as $account)
          <option value="{{ $account->id }}" {{ (string) old('owner_user_id', $editing ? $company->owner_user_id : '') === (string) $account->id ? 'selected' : '' }}>{{ $account->name }} ({{ $account->email }})</option>
          @endforeach
        </select>
        <span class="hint">Changing ownership preserves the former owner as an administrator.</span>
      </div>
      <div class="field"><label for="status">Company status *</label>
        <select class="input" id="status" name="status">
          @foreach(['active' => 'Active', 'suspended' => 'Suspended'] as $value => $label)
            <option value="{{ $value }}" {{ old('status', $editing ? $company->status : 'active') === $value ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select><span class="hint">Suspended companies cannot be accessed through company-scoped APIs.</span>
      </div>
      <div class="field full"><label for="address">Address</label><textarea class="input" id="address" name="address" rows="2" maxlength="4000">{{ old('address', $editing ? $company->address : '') }}</textarea></div>
      <div class="field"><label for="email">Company email</label><input class="input" id="email" name="email" type="email" maxlength="255" value="{{ old('email', $editing ? $company->email : '') }}"></div>
      <div class="field"><label for="phone_number">Phone number</label><input class="input" id="phone_number" name="phone_number" maxlength="40" value="{{ old('phone_number', $editing ? $company->phone_number : '') }}"></div>
      <div class="field"><label for="country_iso_code">Country code *</label><input class="input" id="country_iso_code" name="country_iso_code" required maxlength="2" value="{{ old('country_iso_code', $editing ? $company->country_iso_code : 'CH') }}"></div>
      <div class="field"><label for="dialing_code">Dialing code</label><input class="input" id="dialing_code" name="dialing_code" maxlength="8" value="{{ old('dialing_code', $editing ? $company->dialing_code : '+41') }}"></div>
      <div class="field"><label for="swiss_company_id">Swiss company ID</label><input class="input" id="swiss_company_id" name="swiss_company_id" maxlength="255" value="{{ old('swiss_company_id', $editing ? $company->swiss_company_id : '') }}"></div>
      <div class="field"><label for="vat_number">VAT number</label><input class="input" id="vat_number" name="vat_number" maxlength="255" value="{{ old('vat_number', $editing ? $company->vat_number : '') }}"></div>
      <div class="field"><label for="iban">IBAN</label><input class="input" id="iban" name="iban" maxlength="48" value="{{ old('iban', $editing ? $company->iban : '') }}"></div>
      <div class="field"><label for="qr_iban">QR-IBAN</label><input class="input" id="qr_iban" name="qr_iban" maxlength="48" value="{{ old('qr_iban', $editing ? $company->qr_iban : '') }}"></div>
      <div class="field"><label for="document_numbering">Document numbering</label><input class="input" id="document_numbering" name="document_numbering" maxlength="255" value="{{ old('document_numbering', $editing ? $company->document_numbering : '') }}"></div>
    </div>
    <div class="form-actions"><button type="submit" class="btn btn-primary">{{ $editing ? 'Save company' : 'Create company' }}</button><a href="{{ route('admin.companies.index') }}" class="btn">Cancel</a></div>
  </section>
</form>
@if($editing)
<section class="panel">
  <h2>Company team & permissions</h2><p class="panel-subtitle">Invite or change existing user memberships. Only the selected owner has the owner role.</p>
  <form class="fields" method="post" action="{{ route('admin.companies.members.store', $company) }}">
    @csrf
    <div class="field"><label for="member_user_id">Active user</label><select class="input" id="member_user_id" name="user_id" required>
      <option value="">Select account</option>
      @foreach($availableUsers as $account)<option value="{{ $account->id }}">{{ $account->name }} ({{ $account->email }})</option>@endforeach
    </select></div>
    <div class="field"><label for="member_role">Company role</label><select id="member_role" name="role" class="input"><option value="member">Member</option><option value="admin">Company admin</option></select></div>
    <div class="field full"><button class="btn btn-primary" style="align-self:flex-start" type="submit">Save membership</button></div>
  </form>
  <div class="table-wrap" style="margin-top:22px"><table><thead><tr><th>User</th><th>Role</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach($company->users as $member)
      <tr>
        <td><div class="strong">{{ $member->name }}</div><div class="muted">{{ $member->email }}</div></td>
        <td><span class="pill">{{ ucfirst($member->pivot->role) }}</span></td>
        <td><span class="pill {{ $member->pivot->status === 'active' && $member->is_active ? 'green' : 'red' }}">{{ $member->pivot->status === 'active' && $member->is_active ? 'Active' : 'Inactive' }}</span></td>
        <td>@if($member->id !== $company->owner_user_id)
          <form method="post" action="{{ route('admin.companies.members.destroy', [$company, $member]) }}" onsubmit="return confirm('Remove this company membership?')">
            @csrf @method('delete')<button class="btn btn-small btn-danger" type="submit">Remove</button>
          </form>
        @else<span class="muted">Owner</span>@endif</td>
      </tr>
    @endforeach
  </tbody></table></div>
</section>
@endif
@endsection
