@extends('admin.layout')
@section('title', 'Users — Email OTP Activity')
@section('breadcrumb', 'Users / Email OTP Activity')
@section('heading', 'Email OTP Activity')
@section('description', 'Review email verification attempts for the same user accounts managed under Users. Administrator sign-in remains password-only.')
@section('header_actions')
  <a class="btn btn-outline" href="{{ route('admin.users.index') }}"><x-admin.icon name="arrow-left" /> Back to Users</a>
@endsection

@section('content')
<section class="metrics" aria-label="Authentication statistics">
  <div class="metric"><span class="metric-label">Active Accounts</span><span class="metric-value">{{ number_format($activeUsers) }}</span><span class="metric-small">Accounts allowed to sign in</span></div>
  <div class="metric"><span class="metric-label">Verified Emails</span><span class="metric-value">{{ number_format($verifiedUsers) }}</span><span class="metric-small">Email verification completed</span></div>
  <div class="metric"><span class="metric-label">Pending Verification</span><span class="metric-value">{{ number_format($pendingUsers) }}</span><span class="metric-small">Unverified email addresses</span></div>
  <div class="metric"><span class="metric-label">Mobile OTP Policy</span><span class="metric-value">5 min</span><span class="metric-small">One-time codes expire after 5 minutes</span></div>
</section>
<section class="panel">
  <div class="panel-header"><div><h2>Mobile OTP Requests</h2><p class="panel-header-hint">Only request status and timestamps are displayed. Verification codes and hashes are never shown.</p></div></div>
  <div class="toolbar">
    <form class="search" method="get" action="{{ route('admin.mobile-auth') }}" role="search">
      <input class="input" type="search" name="email" value="{{ $emailFilter }}" placeholder="Filter by account email" aria-label="Search email verification requests">
      <button class="btn btn-primary" type="submit"><x-admin.icon name="search" /> Search</button>
      @if($emailFilter !== '')<a class="btn btn-outline" href="{{ route('admin.mobile-auth') }}">Clear</a>@endif
    </form>
  </div>
  <div class="table-wrap"><table>
    <thead><tr><th>Account</th><th>Purpose</th><th>State</th><th>Issued</th><th>Expires</th></tr></thead>
    <tbody>
      @forelse($recentChallenges as $challenge)
        <tr>
          <td><div class="strong">{{ $challenge->user?->name ?? 'Unknown or deleted account' }}</div><div class="muted">{{ $challenge->user?->email }}</div></td>
          <td>{{ ucfirst($challenge->purpose) }}</td>
          <td>
            @if($challenge->consumed_at)<span class="pill green">Used / Superseded</span>
            @elseif($challenge->expires_at->isPast())<span class="pill amber">Expired</span>
            @elseif($challenge->attempts >= 5)<span class="pill red">Locked</span>
            @else<span class="pill">Awaiting Code</span>@endif
          </td>
          <td class="muted">{{ $challenge->created_at?->format('M d, Y H:i') }}</td>
          <td class="muted">{{ $challenge->expires_at->format('M d, Y H:i') }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty">No email OTP requests found.</td></tr>
      @endforelse
    </tbody>
  </table></div>
  @if($recentChallenges->hasPages())
    <nav class="pagination" aria-label="OTP history pagination">
      @if($recentChallenges->previousPageUrl())<a class="btn btn-small" href="{{ $recentChallenges->previousPageUrl() }}">Previous</a>@endif
      <span>Page {{ $recentChallenges->currentPage() }} of {{ $recentChallenges->lastPage() }}</span>
      @if($recentChallenges->nextPageUrl())<a class="btn btn-small" href="{{ $recentChallenges->nextPageUrl() }}">Next</a>@endif
    </nav>
  @endif
</section>
@endsection
