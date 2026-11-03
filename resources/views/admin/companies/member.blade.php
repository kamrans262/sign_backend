@extends('admin.layout')
@section('title', 'Edit Team Member')
@section('breadcrumb', 'Companies / Team Member')
@section('heading', 'Manage Team Member')
@section('description', 'Update company access and permissions without changing the account profile or company ownership.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.companies.edit', $company) }}"><x-admin.icon name="arrow-left" /> Back to Company</a>
@endsection

@section('content')
<form method="post" action="{{ route('admin.companies.members.update', [$company, $member]) }}">
  @csrf
  @method('patch')
  <section class="panel">
    <div class="panel-header">
      <div>
        <h2>{{ $member->name }}</h2>
        <p class="panel-header-hint">{{ $member->email }} · {{ $company->name }}</p>
      </div>
      <span class="pill"><x-admin.icon name="users" /> Team Access</span>
    </div>
    @if(!$member->is_active)
      <div class="alert error" role="alert">This user account is inactive. Company membership cannot override account-wide suspension.</div>
    @endif
    <div class="fields">
      <div class="field">
        <label for="role">Company Role</label>
        <select class="input" id="role" name="role" required>
          <option value="member" {{ old('role', $member->pivot->role) === 'member' ? 'selected' : '' }}>Member</option>
          <option value="admin" {{ old('role', $member->pivot->role) === 'admin' ? 'selected' : '' }}>Company Admin</option>
        </select>
        <span class="hint">The owner is managed through Company Details and cannot be changed here.</span>
      </div>
      <div class="field">
        <label for="status">Company Membership Status</label>
        <select class="input" id="status" name="status" required>
          <option value="active" {{ old('status', $member->pivot->status) === 'active' ? 'selected' : '' }}>Active</option>
          <option value="inactive" {{ old('status', $member->pivot->status) === 'inactive' ? 'selected' : '' }}>Inactive</option>
        </select>
        <span class="hint">Inactive immediately revokes this company's access on the next authenticated request.</span>
      </div>
    </div>
  </section>
  <section class="panel">
    <div class="panel-header">
      <div>
        <h2>Permissions</h2>
        <p class="panel-header-hint">Select explicit capabilities for a Member. Company Admins retain role-based access.</p>
      </div>
    </div>
    <div class="checks">
      @foreach($allowedPermissions as $permission)
        <label class="check">
          <input type="checkbox" name="permissions[]" value="{{ $permission }}"
            {{ in_array($permission, (array) old('permissions', $selectedPermissions), true) ? 'checked' : '' }}>
          <span>{{ ucwords(str_replace('.', ' · ', $permission)) }}</span>
        </label>
      @endforeach
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><x-admin.icon name="check-circle" /> Save Member Access</button>
      <a class="btn btn-outline" href="{{ route('admin.companies.edit', $company) }}">Cancel</a>
    </div>
  </section>
</form>
@endsection
