<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <title>@yield('title', 'Dashboard') | Sign2Digital Admin</title>
  <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}">
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <a class="brand" href="{{ route('admin.dashboard') }}"><img class="brand-logo" src="{{ asset('admin-assets/logo.png') }}" alt="Sign2Digital"></a>
    <div>
      <div class="side-label">Administration</div>
      <nav class="nav" aria-label="Main navigation">
        <a class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}"><span class="nav-icon">▦</span> Overview</a>
        <a class="{{ request()->routeIs('admin.companies.*') ? 'active' : '' }}" href="{{ route('admin.companies.index') }}"><span class="nav-icon">▣</span> Companies</a>
        <a class="{{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}"><span class="nav-icon">♙</span> Users</a>
        <a class="{{ request()->routeIs('admin.audit') ? 'active' : '' }}" href="{{ route('admin.audit') }}"><span class="nav-icon">≡</span> Audit Trail</a>
      </nav>
    </div>
    <div class="side-bottom">
      <div class="user-name">{{ auth()->user()->name }}</div>
      <div class="user-role">Platform administrator</div>
      <form action="{{ route('admin.logout') }}" method="post" style="margin-top:14px">
        @csrf
        <button class="btn" type="submit">Sign out</button>
      </form>
    </div>
  </aside>
  <main class="main">
    <header class="top">
      <div><p class="eyebrow">Admin Panel / @yield('breadcrumb', 'Overview')</p><h1 class="page-title">@yield('heading', 'Overview')</h1></div>
      <div class="actions">@yield('header_actions')</div>
    </header>
    @if (session('success'))
      <div class="alert success" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
      <div class="alert error" role="alert"><ul class="error-list">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @yield('content')
  </main>
</div>
</body>
</html>
