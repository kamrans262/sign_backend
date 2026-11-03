@extends('admin.layout')
@section('title', 'Users')
@section('breadcrumb', 'Users')
@section('heading', 'User Management')
@section('description', 'View user accounts, manage access, and review company memberships.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.mobile-auth') }}"><x-admin.icon name="shield" /> Email OTP Activity</a>
  <a class="btn btn-primary" href="{{ route('admin.users.create') }}"><x-admin.icon name="plus" /> Add User</a>
@endsection

@section('content')
<section class="panel">
  <div class="panel-header">
    <div><h2>All Users</h2><p class="panel-header-hint">{{ number_format($users->total()) }} {{ $users->total() === 1 ? 'account' : 'accounts' }} found</p></div>
  </div>
  <div class="toolbar">
    <form method="get" action="{{ route('admin.users.index') }}" class="search" role="search">
      <input class="input" type="search" name="q" value="{{ $search }}" placeholder="Search name or email" aria-label="Search users">
      <select class="input" name="status" aria-label="Filter user status">
        <option value="">All Statuses</option>
        <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
        <option value="inactive" {{ $status === 'inactive' ? 'selected' : '' }}>Inactive</option>
      </select>
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($search !== '' || $status !== '')<a class="btn btn-outline" href="{{ route('admin.users.index') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th scope="col">Name / Email</th><th scope="col">Companies</th><th scope="col">Role</th><th scope="col">Email</th><th scope="col">Status</th><th scope="col">Joined</th><th scope="col">Action</th></tr></thead>
      <tbody>
        @forelse($users as $user)
          <tr>
            <td><div class="strong">{{ $user->name }}</div><div class="muted">{{ $user->email }}</div></td>
            <td>{{ $user->companies_count }}</td>
            <td><span class="pill">{{ $user->is_platform_admin ? 'Platform Admin' : 'App User' }}</span></td>
            <td><span class="pill {{ $user->email_verified_at ? 'green' : 'amber' }}">{{ $user->email_verified_at ? 'Verified' : 'Pending OTP' }}</span></td>
            <td><span class="pill {{ $user->is_active ? 'green' : 'red' }}"><span class="pill-dot"></span> {{ $user->is_active ? 'Active' : 'Inactive' }}</span></td>
            <td class="muted">{{ $user->created_at?->format('M d, Y') }}</td>
            <td><a class="btn btn-small btn-outline" href="{{ route('admin.users.edit', $user) }}">Manage <x-admin.icon name="arrow-right" /></a></td>
          </tr>
        @empty
          <tr><td colspan="7" class="empty">No matching users. Try a different search.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($users->hasPages())
    <nav class="pagination" aria-label="User list pagination">
      @if($users->previousPageUrl())<a class="btn btn-small" href="{{ $users->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $users->currentPage() }} of {{ $users->lastPage() }}</span>
      @if($users->nextPageUrl())<a class="btn btn-small" href="{{ $users->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
