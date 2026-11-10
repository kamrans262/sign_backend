<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <meta name="theme-color" content="#FFFFFF">
  <title>@yield('title', 'Overview') | Sign2Digital Admin</title>
  <link rel="stylesheet" href="{{ route('admin.asset', ['file' => 'admin.css'], false) }}?v=6">
</head>
<body class="admin-body hope-ui-adapt">
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="app-shell">
  <aside class="app-sidebar" aria-label="Administrator sidebar">
    <div class="sidebar-top">
      <a class="brand" href="{{ route('admin.dashboard') }}" aria-label="Sign2Digital Admin Home">
        <img class="brand-logo" src="{{ route('admin.asset', ['file' => 'logo.png'], false) }}" alt="Sign2Digital" width="165" height="55">
      </a>
    </div>
    <div class="sidebar-heading">MODULES</div>
    <nav class="side-nav" aria-label="Administration">
      <a class="side-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}" @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif>
        <x-admin.icon name="overview" /><span>Dashboard</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.search.*') ? 'active' : '' }}" href="{{ route('admin.search.index') }}" @if(request()->routeIs('admin.search.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Global Search</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.companies.*') ? 'active' : '' }}" href="{{ route('admin.companies.index') }}" @if(request()->routeIs('admin.companies.*')) aria-current="page" @endif>
        <x-admin.icon name="building" /><span>Company Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.clients.*') ? 'active' : '' }}" href="{{ route('admin.clients.index') }}" @if(request()->routeIs('admin.clients.*')) aria-current="page" @endif>
        <x-admin.icon name="users" /><span>Client Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.jobs.*') ? 'active' : '' }}" href="{{ route('admin.jobs.index') }}" @if(request()->routeIs('admin.jobs.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Job Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.job-media.*') ? 'active' : '' }}" href="{{ route('admin.job-media.index') }}" @if(request()->routeIs('admin.job-media.*'))aria-current="page"@endif><x-admin.icon name="briefcase" /><span>Photos & Notes Management</span></a>
    <a class="side-link {{ request()->routeIs('admin.quotes.*') ? 'active' : '' }}" href="{{ route('admin.quotes.index') }}" @if(request()->routeIs('admin.quotes.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Quote Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.documents.*') ? 'active' : '' }}" href="{{ route('admin.documents.index') }}" @if(request()->routeIs('admin.documents.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Document Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.acceptances.*') ? 'active' : '' }}" href="{{ route('admin.acceptances.index') }}"><x-admin.icon name="briefcase"/><span>Work Acceptance Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.labour.*') ? 'active' : '' }}" href="{{ route('admin.labour.index') }}" @if(request()->routeIs('admin.labour.*'))aria-current="page"@endif><x-admin.icon name="history"/><span>Time & Labour Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.deposits.*') ? 'active' : '' }}" href="{{ route('admin.deposits.index') }}"><x-admin.icon name="briefcase"/><span>Deposit Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.invoices.*') ? 'active' : '' }}" href="{{ route('admin.invoices.index') }}"><x-admin.icon name="briefcase"/><span>Invoice Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.expenses.*') ? 'active' : '' }}" href="{{ route('admin.expenses.index') }}" @if(request()->routeIs('admin.expenses.*'))aria-current="page"@endif><x-admin.icon name="briefcase"/><span>Expenses Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.equipment.*') ? 'active' : '' }}" href="{{ route('admin.equipment.index') }}" @if(request()->routeIs('admin.equipment.*'))aria-current="page"@endif><x-admin.icon name="briefcase"/><span>Equipment &amp; Loans Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.reminders.*') ? 'active' : '' }}" href="{{ route('admin.reminders.index') }}" @if(request()->routeIs('admin.reminders.*')) aria-current="page" @endif><x-admin.icon name="history" /><span>Reminders Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}" href="{{ route('admin.notifications.index') }}" @if(request()->routeIs('admin.notifications.*')) aria-current="page" @endif><x-admin.icon name="history" /><span>Notifications &amp; Templates</span></a>
      <a class="side-link {{ request()->routeIs('admin.reports.*') ? 'active' : '' }}" href="{{ route('admin.reports.index') }}" @if(request()->routeIs('admin.reports.*'))aria-current="page"@endif><x-admin.icon name="briefcase"/><span>Reports Management</span></a>
      <a class="side-link {{ request()->routeIs('admin.addenda.*') ? 'active' : '' }}" href="{{ route('admin.addenda.index') }}" @if(request()->routeIs('admin.addenda.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Addenda Management</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.signatures.*') ? 'active' : '' }}" href="{{ route('admin.signatures.index') }}" @if(request()->routeIs('admin.signatures.*')) aria-current="page" @endif>
        <x-admin.icon name="history" /><span>Digital Signatures</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.users.*') || request()->routeIs('admin.mobile-auth') ? 'active' : '' }}" href="{{ route('admin.users.index') }}" @if(request()->routeIs('admin.users.*') || request()->routeIs('admin.mobile-auth')) aria-current="page" @endif>
        <x-admin.icon name="users" /><span>Users</span>
      </a>

      <a class="side-link {{ request()->routeIs('admin.billing.*') ? 'active' : '' }}" href="{{ route('admin.billing.index') }}" @if(request()->routeIs('admin.billing.*')) aria-current="page" @endif>
        <x-admin.icon name="briefcase" /><span>Subscriptions &amp; Billing</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.support.*') ? 'active' : '' }}" href="{{ route('admin.support.index') }}" @if(request()->routeIs('admin.support.*')) aria-current="page" @endif>
        <x-admin.icon name="users" /><span>Help &amp; Support</span>
      </a>
      <a class="side-link {{ request()->routeIs('admin.audit') ? 'active' : '' }}" href="{{ route('admin.audit') }}" @if(request()->routeIs('admin.audit')) aria-current="page" @endif>
        <x-admin.icon name="history" /><span>Audit Trail</span>
      </a>
    </nav>
    <div class="sidebar-bottom">
      <div class="account-card">
        <div class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim(auth()->user()->name), 0, 1)) }}</div>
        <div class="account-copy">
          <strong>{{ auth()->user()->name }}</strong>
          <span>Platform Administrator</span>
        </div>
      </div>
      <form action="{{ route('admin.logout') }}" method="post">
        @csrf
        <button class="btn btn-outline logout-btn" type="submit"><x-admin.icon name="logout" /> Sign out</button>
      </form>
    </div>
  </aside>

  <div class="app-workspace">
    <header class="app-header">
      <a class="mobile-brand" href="{{ route('admin.dashboard') }}" aria-label="Sign2Digital Admin Home">
        <img src="{{ route('admin.asset', ['file' => 'logo.png'], false) }}" alt="Sign2Digital" width="160" height="53">
      </a>
      <div class="header-left">
        <span class="header-section">Administration</span>
        <span class="header-separator">/</span>
        <span class="header-location">@if(request()->routeIs('admin.dashboard'))Dashboard @else @yield('breadcrumb', 'Overview') @endif</span>
      </div>
      <div class="header-right">
        <a class="hope-top-action" href="{{ route('admin.companies.create') }}"><x-admin.icon name="plus" /> Add Company</a><span class="header-admin-label">Administrator</span>
        <span class="header-avatar" title="{{ auth()->user()->name }}" aria-label="Signed in as {{ auth()->user()->name }}">{{ mb_strtoupper(mb_substr(trim(auth()->user()->name), 0, 1)) }}</span>
        <form action="{{ route('admin.logout') }}" method="post" class="header-logout">
          @csrf
          <button class="icon-button" title="Sign out" aria-label="Sign out" type="submit"><x-admin.icon name="logout" /></button>
        </form>
      </div>
    </header>

    <details class="mobile-menu">
      <summary><x-admin.icon name="menu" /><span>Menu</span><x-admin.icon name="chevron-down" /></summary>
      <nav aria-label="Mobile administration">
        <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}"><x-admin.icon name="overview" /> Dashboard</a>
        <a href="{{ route('admin.companies.index') }}" class="{{ request()->routeIs('admin.companies.*') ? 'active' : '' }}"><x-admin.icon name="building" /> Company Management</a>
        <a href="{{ route('admin.billing.index') }}" class="{{ request()->routeIs('admin.billing.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Subscriptions &amp; Billing</a>

        <a href="{{ route('admin.clients.index') }}" class="{{ request()->routeIs('admin.clients.*') ? 'active' : '' }}"><x-admin.icon name="users" /> Client Management</a>
        <a href="{{ route('admin.jobs.index') }}" class="{{ request()->routeIs('admin.jobs.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Job Management</a>
        <a href="{{ route('admin.job-media.index') }}" class="{{ request()->routeIs('admin.job-media.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Photos & Notes Management</a>
        <a href="{{ route('admin.quotes.index') }}" class="{{ request()->routeIs('admin.quotes.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Quote Management</a>
        <a href="{{ route('admin.documents.index') }}" class="{{ request()->routeIs('admin.documents.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Document Management</a>
        <a href="{{ route('admin.acceptances.index') }}" class="{{ request()->routeIs('admin.acceptances.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Work Acceptance Management</a>
        <a href="{{ route('admin.labour.index') }}" class="{{ request()->routeIs('admin.labour.*') ? 'active' : '' }}"><x-admin.icon name="history" /> Time & Labour Management</a>
      <a href="{{ route('admin.deposits.index') }}" class="{{ request()->routeIs('admin.deposits.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Deposit Management</a>
      <a href="{{ route('admin.invoices.index') }}" class="{{ request()->routeIs('admin.invoices.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Invoice Management</a>
      <a href="{{ route('admin.expenses.index') }}" class="{{ request()->routeIs('admin.expenses.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Expenses Management</a>
        <a href="{{ route('admin.equipment.index') }}" class="{{ request()->routeIs('admin.equipment.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Equipment &amp; Loans Management</a>
        <a href="{{ route('admin.reminders.index') }}" class="{{ request()->routeIs('admin.reminders.*') ? 'active' : '' }}"><x-admin.icon name="history" /> Reminders Management</a>
        <a href="{{ route('admin.notifications.index') }}" class="{{ request()->routeIs('admin.notifications.*') ? 'active' : '' }}"><x-admin.icon name="history" /> Notifications &amp; Templates</a>
      <a href="{{ route('admin.reports.index') }}" class="{{ request()->routeIs('admin.reports.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Reports Management</a>
        <a href="{{ route('admin.addenda.index') }}" class="{{ request()->routeIs('admin.addenda.*') ? 'active' : '' }}"><x-admin.icon name="briefcase" /> Addenda Management</a>
        <a href="{{ route('admin.signatures.index') }}" class="{{ request()->routeIs('admin.signatures.*') ? 'active' : '' }}"><x-admin.icon name="history" /> Digital Signatures</a>
        <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') || request()->routeIs('admin.mobile-auth') ? 'active' : '' }}"><x-admin.icon name="users" /> Users</a>
        <a href="{{ route('admin.support.index') }}" class="{{ request()->routeIs('admin.support.*') ? 'active' : '' }}"><x-admin.icon name="users" /> Help &amp; Support</a>
        <a href="{{ route('admin.audit') }}" class="{{ request()->routeIs('admin.audit') ? 'active' : '' }}"><x-admin.icon name="history" /> Audit Trail</a>
      </nav>
    </details>

    <main id="main-content" class="main" tabindex="-1">
      @unless(request()->routeIs('admin.dashboard'))
      <header class="top">
        <div class="page-heading">
          <p class="eyebrow">Home <span class="eyebrow-separator">/</span> @yield('breadcrumb', 'Overview')</p>
          <h1 class="page-title">@yield('heading', 'Overview')</h1>
          @hasSection('description') <p class="page-description">@yield('description')</p> @endif
        </div>
        <div class="actions">@yield('header_actions')</div>
      </header>
      @endunless

      @if(session('success'))
        <div class="alert success" role="status"><x-admin.icon name="check-circle" /> <span>{{ session('success') }}</span></div>
      @endif
      @if($errors->any())
        <div class="alert error" role="alert">
          <x-admin.icon name="alert-circle" />
          <ul class="error-list">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
      @endif
      @yield('content')
      <footer class="admin-footer">Sign2Digital</footer>
    </main>
  </div>
</div>

<dialog id="admin-confirm-dialog" class="admin-confirm-dialog" aria-labelledby="admin-confirm-title" aria-describedby="admin-confirm-message">
  <div class="admin-confirm-inner">
    <div class="admin-confirm-icon" aria-hidden="true"><x-admin.icon name="alert-circle" /></div>
    <h2 id="admin-confirm-title">Confirm action</h2>
    <p id="admin-confirm-message"></p>
    <div class="admin-confirm-actions">
      <button type="button" class="btn btn-outline" id="admin-confirm-cancel">Cancel</button>
      <button type="button" class="btn btn-primary" id="admin-confirm-approve">Confirm</button>
    </div>
  </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const dialog = document.getElementById('admin-confirm-dialog');
  const message = document.getElementById('admin-confirm-message');
  const cancel = document.getElementById('admin-confirm-cancel');
  const approve = document.getElementById('admin-confirm-approve');
  let pending = null;
  document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-confirm')) return;
    if (form.dataset.confirmApproved === '1') {
      delete form.dataset.confirmApproved;
      return;
    }
    event.preventDefault();
    pending = { form: form, submitter: event.submitter };
    message.textContent = form.getAttribute('data-confirm') || 'Proceed with this action?';
    dialog.showModal();
    cancel.focus();
  }, true);
  cancel.addEventListener('click', function () { dialog.close(); });
  dialog.addEventListener('close', function () { pending = null; });
  approve.addEventListener('click', function () {
    const choice = pending;
    dialog.close();
    if (!choice || !choice.form.isConnected) return;
    choice.form.dataset.confirmApproved = '1';
    if (choice.submitter && choice.submitter.form === choice.form) {
      choice.form.requestSubmit(choice.submitter);
    } else {
      choice.form.requestSubmit();
    }
  });
});
</script>
</body>
</html>
