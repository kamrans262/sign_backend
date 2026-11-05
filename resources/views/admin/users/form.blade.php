@extends('admin.layout')
@php($editing = isset($user))
@section('title', $editing ? 'Edit User' : 'Add User')
@section('breadcrumb', 'Users / '.($editing ? 'Edit User' : 'Add User'))
@section('heading', $editing ? 'Manage User' : 'Add New User')
@section('description', $editing ? 'Update account details, password and platform access.' : 'Create a new user account for Sign2Digital.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.users.index') }}"><x-admin.icon name="arrow-left" /> Back to Users</a>
@endsection

@section('content')
<form method="post" action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}">
  @csrf
  @if($editing)@method('put')@endif

  <section class="panel">
    <div class="panel-header">
      <div><h2>Personal Information</h2><p class="panel-header-hint">Enter the account details exactly as they should appear in the application.</p></div>
      <span class="pill"><x-admin.icon name="users" /> User Profile</span>
    </div>
    <div class="fields">
      <div class="field">
        <label for="name">Full Name <span class="required-mark">*</span></label>
        <input class="input" id="name" name="name" required maxlength="255" autocomplete="name" placeholder="Enter full name" value="{{ old('name', $editing ? $user->name : '') }}">
      </div>
      <div class="field">
        <label for="email">Email Address <span class="required-mark">*</span></label>
        <input class="input" id="email" type="email" name="email" required maxlength="255" autocomplete="email" placeholder="Enter email address" value="{{ old('email', $editing ? $user->email : '') }}">
      </div>
      <div class="field">
        <label for="phone_number">Phone Number</label>
        <input class="input" id="phone_number" name="phone_number" type="tel" maxlength="40" autocomplete="tel" placeholder="Enter phone number" value="{{ old('phone_number', $editing ? $user->phone_number : '') }}">
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div><h2>Security</h2><p class="panel-header-hint">{{ $editing ? 'Leave password fields empty to keep the current password.' : 'Set a strong password for this account.' }}</p></div>
      <span class="pill"><x-admin.icon name="lock" /> Authentication</span>
    </div>
    <div class="fields">
      <div class="field">
        <label for="password">{{ $editing ? 'New Password (optional)' : 'Password *' }}</label>
        <input class="input" id="password" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Minimum 12 characters" {{ $editing ? '' : 'required' }}>
        <span class="hint">At least 12 characters. Passwords are never displayed after saving.</span>
      </div>
      <div class="field">
        <label for="password_confirmation">Confirm Password</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" placeholder="Repeat new password">
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header">
      <div><h2>Account Permissions</h2><p class="panel-header-hint">Control who can sign in and who can access the platform-wide admin panel.</p></div>
      <span class="pill"><x-admin.icon name="shield" /> Access Control</span>
    </div>
    <input type="hidden" name="is_active" value="0">
    <input type="hidden" name="is_platform_admin" value="0">
    <div class="preference-grid">
      <label class="preference-card">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $editing ? $user->is_active : true) ? 'checked' : '' }}>
        <span class="preference-copy"><strong>Active Account</strong><span>Allow the user to access Sign2Digital. Disabling revokes existing mobile API tokens.</span></span>
      </label>
      <label class="preference-card">
        <input type="checkbox" name="is_platform_admin" value="1" {{ old('is_platform_admin', $editing ? $user->is_platform_admin : false) ? 'checked' : '' }}>
        <span class="preference-copy"><strong>Platform Administrator</strong><span>Grant access to all platform administration screens. Company roles remain separate.</span></span>
      </label>
    </div>
    @if($editing)
      <p class="hint account-meta">Active mobile sessions: {{ $user->tokens()->count() }}. Revocation does not delete jobs, documents or user data.</p>
    @endif
    @if($editing)
      <p class="hint account-meta">Email verification: {{ $user->email_verified_at ? 'Verified' : 'Pending OTP' }} · User #{{ $user->id }} · Created {{ $user->created_at?->format('M d, Y') }} · {{ $user->companies()->count() }} company memberships</p>
    @endif
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><x-admin.icon name="check-circle" /> {{ $editing ? 'Save Changes' : 'Create User' }}</button>
      <a class="btn btn-outline" href="{{ route('admin.users.index') }}">Cancel</a>
    </div>
  </section>
</form>
@if($editing)
<section class="panel">
<div class="panel-header"><h2>Security Session Oversight</h2></div>
<form method="post" action="{{ route('admin.users.revoke-sessions',$user) }}">
@csrf
<div class="field"><label for="admin_password">Confirm your administrator password</label>
<input class="input" id="admin_password" type="password" name="admin_password" autocomplete="current-password" required></div>
<div class="form-actions"><button class="btn btn-outline">Revoke User Sessions</button></div>
</form>
</section>
@endif
@endsection
