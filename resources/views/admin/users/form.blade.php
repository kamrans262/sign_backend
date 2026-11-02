@extends('admin.layout')
@php($editing = isset($user))
@section('title', $editing ? 'Edit User' : 'New User')
@section('breadcrumb', 'Users / '.($editing ? 'Edit' : 'New'))
@section('heading', $editing ? 'Manage account' : 'Add a new account')
@section('header_actions')
  <a class="btn" href="{{ route('admin.users.index') }}">All users</a>
@endsection
@section('content')
<form method="post" action="{{ $editing ? route('admin.users.update', $user) : route('admin.users.store') }}">
  @csrf
  @if($editing)@method('put')@endif
  <section class="panel">
    <h2>Account details</h2>
    <p class="panel-subtitle">Manage identity and platform access. Existing company memberships are managed under Companies.</p>
    <div class="fields">
      <div class="field"><label for="name">Full name *</label><input class="input" id="name" name="name" required maxlength="255" value="{{ old('name', $editing ? $user->name : '') }}"></div>
      <div class="field"><label for="email">Email *</label><input class="input" id="email" type="email" name="email" required maxlength="255" value="{{ old('email', $editing ? $user->email : '') }}"></div>
      <div class="field"><label for="phone_number">Phone number</label><input class="input" id="phone_number" name="phone_number" maxlength="40" value="{{ old('phone_number', $editing ? $user->phone_number : '') }}"></div>
      <div class="field"><label for="password">{{ $editing ? 'New password (optional)' : 'Password *' }}</label><input class="input" id="password" name="password" type="password" minlength="12" autocomplete="new-password" {{ $editing ? '' : 'required' }}><span class="hint">At least 12 characters; never displayed after saving.</span></div>
      <div class="field"><label for="password_confirmation">Confirm password</label><input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password"></div>
    </div>
  </section>
  <section class="panel">
    <h2>Access controls</h2>
    <p class="panel-subtitle">Disabling an account immediately revokes all of its mobile API tokens. Company roles do not grant platform-wide administrator access.</p>
    <div class="checks">
      <label class="check"><input type="checkbox" name="is_active" value="1" {{ old('is_active', $editing ? $user->is_active : true) ? 'checked' : '' }}> Active account</label>
      <label class="check"><input type="checkbox" name="is_platform_admin" value="1" {{ old('is_platform_admin', $editing ? $user->is_platform_admin : false) ? 'checked' : '' }}> Platform administrator</label>
    </div>
    @if($editing)<div class="hint" style="margin-top:15px">User #{{ $user->id }} · Joined {{ $user->created_at?->format('M d, Y') }} · {{ $user->companies()->count() }} company memberships</div>@endif
    <div class="form-actions">
      <button class="btn btn-primary" type="submit">{{ $editing ? 'Save changes' : 'Create account' }}</button>
      <a class="btn" href="{{ route('admin.users.index') }}">Cancel</a>
    </div>
  </section>
</form>
@endsection
