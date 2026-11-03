@extends('admin.layout')
@php($editing = isset($company))
@section('title', $editing ? 'Edit Company' : 'Add Company')
@section('breadcrumb', 'Companies / '.($editing ? 'Edit Company' : 'Add Company'))
@section('heading', $editing ? 'Manage Company' : 'Add New Company')
@section('description', $editing ? 'Update business information, company status and team access.' : 'Create a new company and assign its initial owner.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.companies.index') }}"><x-admin.icon name="arrow-left" /> Back to Companies</a>
@endsection

@section('content')
<form method="post" action="{{ $editing ? route('admin.companies.update', $company) : route('admin.companies.store') }}">
  @csrf
  @if($editing)@method('put')@endif

  <section class="panel">
    <div class="panel-header">
      <div><h2>Company Details</h2><p class="panel-header-hint">Business identity and ownership of the company's records.</p></div>
      <span class="pill"><x-admin.icon name="building" /> Company Profile</span>
    </div>
    <div class="fields">
      <div class="field full">
        <label for="name">Company Name <span class="required-mark">*</span></label>
        <input class="input" id="name" name="name" required maxlength="255" placeholder="Enter company name" value="{{ old('name', $editing ? $company->name : '') }}">
      </div>
      <div class="field">
        <label for="owner_user_id">Company Owner <span class="required-mark">*</span></label>
        <select class="input" id="owner_user_id" name="owner_user_id" required>
          <option value="">Select an active user</option>
          @foreach($availableUsers as $account)
            <option value="{{ $account->id }}" {{ (string) old('owner_user_id', $editing ? $company->owner_user_id : '') === (string) $account->id ? 'selected' : '' }}>{{ $account->name }} ({{ $account->email }})</option>
          @endforeach
        </select>
        <span class="hint">Changing ownership preserves the previous owner as a company administrator.</span>
      </div>
      <div class="field">
        <label for="status">Company Status <span class="required-mark">*</span></label>
        <select class="input" id="status" name="status" required>
          <option value="active" {{ old('status', $editing ? $company->status : 'active') === 'active' ? 'selected' : '' }}>Active</option>
          <option value="suspended" {{ old('status', $editing ? $company->status : 'active') === 'suspended' ? 'selected' : '' }}>Suspended</option>
        </select>
        <span class="hint">Suspending a company blocks its company-scoped API access.</span>
      </div>
      <div class="field full">
        <label for="address">Company Address</label>
        <textarea class="input" id="address" name="address" rows="3" maxlength="4000" placeholder="Enter full address">{{ old('address', $editing ? $company->address : '') }}</textarea>
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div><h2>Contact Information</h2><p class="panel-header-hint">Company contact details used throughout the application.</p></div>
    </div>
    <div class="fields">
      <div class="field">
        <label for="email">Business Email</label>
        <input class="input" id="email" name="email" type="email" maxlength="255" placeholder="company@example.com" value="{{ old('email', $editing ? $company->email : '') }}">
      </div>
      <div class="field">
        <label for="phone_number">Phone Number</label>
        <input class="input" id="phone_number" name="phone_number" type="tel" maxlength="40" placeholder="Enter phone number" value="{{ old('phone_number', $editing ? $company->phone_number : '') }}">
      </div>
      <div class="field">
        <label for="country_iso_code">Country Code <span class="required-mark">*</span></label>
        <input class="input" id="country_iso_code" name="country_iso_code" required maxlength="2" placeholder="CH" value="{{ old('country_iso_code', $editing ? $company->country_iso_code : 'CH') }}">
      </div>
      <div class="field">
        <label for="dialing_code">Dialing Code</label>
        <input class="input" id="dialing_code" name="dialing_code" maxlength="8" placeholder="+41" value="{{ old('dialing_code', $editing ? $company->dialing_code : '+41') }}">
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div><h2>Company Billing & Documents</h2><p class="panel-header-hint">Keep the existing Swiss business, payment and document-numbering fields.</p></div>
    </div>
    <div class="fields">
      <div class="field">
        <label for="swiss_company_id">Swiss Company ID</label>
        <input class="input" id="swiss_company_id" name="swiss_company_id" maxlength="255" placeholder="Enter company ID" value="{{ old('swiss_company_id', $editing ? $company->swiss_company_id : '') }}">
      </div>
      <div class="field">
        <label for="vat_number">VAT Number</label>
        <input class="input" id="vat_number" name="vat_number" maxlength="255" placeholder="Enter VAT number" value="{{ old('vat_number', $editing ? $company->vat_number : '') }}">
      </div>
      <div class="field">
        <label for="iban">IBAN</label>
        <input class="input" id="iban" name="iban" maxlength="48" placeholder="Enter IBAN" value="{{ old('iban', $editing ? $company->iban : '') }}">
      </div>
      <div class="field">
        <label for="qr_iban">QR-IBAN</label>
        <input class="input" id="qr_iban" name="qr_iban" maxlength="48" placeholder="Enter QR-IBAN" value="{{ old('qr_iban', $editing ? $company->qr_iban : '') }}">
      </div>
      <div class="field">
        <label for="document_numbering">Document Numbering</label>
        <input class="input" id="document_numbering" name="document_numbering" maxlength="255" placeholder="e.g. Q-2900" value="{{ old('document_numbering', $editing ? $company->document_numbering : '') }}">
      </div>
    </div>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><x-admin.icon name="check-circle" /> {{ $editing ? 'Save Changes' : 'Create Company' }}</button>
      <a href="{{ route('admin.companies.index') }}" class="btn btn-outline">Cancel</a>
    </div>
  </section>
</form>

@if($editing)
<section class="panel">
  <div class="panel-header">
    <div><h2>Team Members & Roles</h2><p class="panel-header-hint">Add an existing active user or change a membership role. Company ownership is managed above.</p></div>
    <span class="pill"><x-admin.icon name="users" /> {{ $company->users->count() }} Members</span>
  </div>
  <form class="fields" method="post" action="{{ route('admin.companies.members.store', $company) }}">
    @csrf
    <div class="field">
      <label for="member_user_id">User</label>
      <select class="input" id="member_user_id" name="user_id" required>
        <option value="">Select an active user</option>
        @foreach($availableUsers as $account)
          <option value="{{ $account->id }}">{{ $account->name }} ({{ $account->email }})</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="member_role">Company Role</label>
      <select id="member_role" name="role" class="input">
        <option value="member">Member</option>
        <option value="admin">Company Admin</option>
      </select>
    </div>
    <div class="field full"><button class="btn btn-primary" style="align-self:flex-start" type="submit"><x-admin.icon name="plus" /> Save Membership</button></div>
  </form>
  <div class="table-wrap membership-table">
    <table>
      <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
      <tbody>
        @forelse($company->users as $member)
          <tr>
            <td><span class="strong">{{ $member->name }}</span><div class="muted">{{ $member->email }}</div></td>
            <td><span class="pill">{{ ucfirst($member->pivot->role) }}</span></td>
            <td><span class="pill {{ $member->pivot->status === 'active' && $member->is_active ? 'green' : 'red' }}"><span class="pill-dot"></span> {{ $member->pivot->status === 'active' && $member->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td>
              @if($member->id !== $company->owner_user_id)
                <form method="post" action="{{ route('admin.companies.members.destroy', [$company, $member]) }}" onsubmit="return confirm('Remove this company membership?')">
                  @csrf @method('delete')
                  <button class="btn btn-small btn-danger" type="submit">Remove</button>
                </form>
              @else
                <span class="muted">Owner</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No company members registered.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
@endif


@if($editing)
<section class="panel">
  <div class="panel-header">
    <div><h2>Company Files</h2><p class="panel-header-hint">Private company logo and PDF terms. Only administrators and authorized company members can access these files.</p></div>
  </div>
  <div class="fields">
    @foreach(['logo' => 'Company Logo', 'terms' => 'Terms & Conditions (PDF)'] as $kind => $label)
      @php($exists = $kind === 'logo' ? (bool) $company->logo_path : (bool) $company->terms_path)
      @php($filename = $kind === 'logo' ? $company->logo_original_name : $company->terms_original_name)
      <div class="field">
        <label>{{ $label }}</label>
        <p class="hint">{{ $exists ? ($filename ?? 'Uploaded file') : 'No file uploaded yet.' }}</p>
        <form method="post" enctype="multipart/form-data" action="{{ route('admin.companies.files.upload', [$company, $kind]) }}">
          @csrf
          <input class="input" name="file" type="file" required
            accept="{{ $kind === 'logo' ? '.png,.jpg,.jpeg,.svg' : '.pdf' }}" aria-label="Upload {{ $label }} (max 2 MB)">
          <div class="form-actions"><button class="btn btn-primary" type="submit"><x-admin.icon name="plus" /> Upload {{ $label }}</button></div>
        </form>
        @if($exists)
          <a class="btn btn-outline" href="{{ route('admin.companies.files.download', [$company, $kind]) }}">Download current file</a>
          <form method="post" action="{{ route('admin.companies.files.destroy', [$company, $kind]) }}">
            @csrf @method('delete')
            <button type="submit" class="btn btn-danger">Remove {{ $label }}</button>
          </form>
        @endif
      </div>
    @endforeach
  </div>
</section>

<section class="panel">
  <div class="panel-header">
    <div><h2>Email Invitations</h2><p class="panel-header-hint">Invite a team member by email. Codes expire in seven days and cannot be viewed here.</p></div>
  </div>
  <form method="post" class="fields" action="{{ route('admin.companies.invitations.store', $company) }}">
    @csrf
    <div class="field">
      <label for="invite_email">Recipient Email</label>
      <input type="email" name="email" id="invite_email" required class="input" maxlength="255" placeholder="colleague@company.com">
    </div>
    <div class="field">
      <label for="invite_role">Company Role</label>
      <select id="invite_role" name="role" class="input" required>
        <option value="member">Member</option>
        <option value="admin">Company Admin</option>
      </select>
    </div>
    <div class="field full">
      <button type="submit" class="btn btn-primary"><x-admin.icon name="plus" /> Send Invitation</button>
    </div>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Expiry</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($company->invitations->sortByDesc('created_at') as $invite)
        @php($state = $invite->accepted_at ? 'Accepted' : ($invite->revoked_at ? 'Revoked' : ($invite->expires_at->isPast() ? 'Expired' : 'Pending')))
        <tr>
          <td>{{ $invite->email }}</td>
          <td>{{ ucfirst($invite->role) }}</td>
          <td><span class="pill {{ $state === 'Accepted' ? 'green' : ($state === 'Pending' ? 'amber' : '') }}">{{ $state }}</span></td>
          <td class="muted">{{ $invite->expires_at->format('M d, Y') }}</td>
          <td>
            @if($state === 'Pending')
              <form method="post" action="{{ route('admin.companies.invitations.destroy', [$company, $invite]) }}">
                @csrf @method('delete')
                <button type="submit" class="btn btn-small btn-outline">Revoke</button>
              </form>
            @else
              <span class="muted">—</span>
            @endif
          </td>
        </tr>
      @empty
        <tr><td class="empty" colspan="5">No company invitations yet.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@endif
@endsection
