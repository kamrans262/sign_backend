@extends('admin.layout')
@section('title', 'Users')
@section('breadcrumb', 'Users')
@section('heading', 'User management')
@section('header_actions')
  <a class="btn btn-primary" href="{{ route('admin.users.create') }}">+ Add user</a>
@endsection
@section('content')
<section class="panel">
  <div class="toolbar">
    <form method="get" action="{{ route('admin.users.index') }}" class="search" role="search">
      <input class="input" type="search" name="q" value="{{ $search }}" placeholder="Search by name or email" aria-label="Search users">
      <button class="btn" type="submit">Search</button>
      @if($search !== '')<a class="btn" href="{{ route('admin.users.index') }}">Clear</a>@endif
    </form>
    <span class="muted">{{ $users->total() }} accounts</span>
  </div>
  <div class="table-wrap">
    <table><thead><tr><th>Account</th><th>Companies</th><th>Access</th><th>Status</th><th>Joined</th><th></th></tr></thead><tbody>
      @forelse($users as $user)
        <tr>
          <td><div class="strong">{{ $user->name }}</div><div class="muted">{{ $user->email }}</div></td>
          <td>{{ $user->companies_count }}</td>
          <td><span class="pill">{{ $user->is_platform_admin ? 'Platform Admin' : 'App User' }}</span></td>
          <td><span class="pill {{ $user->is_active ? 'green' : 'red' }}">{{ $user->is_active ? 'Active' : 'Inactive' }}</span></td>
          <td>{{ $user->created_at?->format('M d, Y') }}</td>
          <td><a class="btn btn-small" href="{{ route('admin.users.edit', $user) }}">Manage</a></td>
        </tr>
      @empty<tr><td colspan="6" class="empty">No users match the search.</td></tr>@endforelse
    </tbody></table>
  </div>
  @if($users->hasPages())
    <div class="pagination">
      @if($users->previousPageUrl())<a class="btn btn-small" href="{{ $users->previousPageUrl() }}">Previous</a>@endif
      Page {{ $users->currentPage() }} of {{ $users->lastPage() }}
      @if($users->nextPageUrl())<a class="btn btn-small" href="{{ $users->nextPageUrl() }}">Next</a>@endif
    </div>
  @endif
</section>
@endsection
