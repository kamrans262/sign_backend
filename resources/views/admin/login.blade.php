<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="referrer" content="strict-origin-when-cross-origin">
  <meta name="theme-color" content="#FFFFFF">
  <title>Administrator Login | Sign2Digital</title>
  <link rel="stylesheet" href="{{ route('admin.asset', ['file' => 'admin.css']) }}?v=3">
</head>
<body class="login-page">
<main class="login-wrap">
  <a class="login-logo" href="{{ route('admin.login') }}" aria-label="Sign2Digital administrator login">
    <img src="{{ route('admin.asset', ['file' => 'logo.png']) }}" alt="Sign2Digital" width="210" height="70">
  </a>
  <section class="login-panel" aria-labelledby="login-heading">
    <div class="login-heading-icon"><x-admin.icon name="shield" /></div>
    <h1 id="login-heading">Admin Panel</h1>
    <p class="login-subtitle">Sign in to manage your Sign2Digital platform.</p>

    @if($errors->any())
      <div class="alert error" role="alert"><x-admin.icon name="alert-circle" /><span>{{ $errors->first() }}</span></div>
    @endif

    <form method="post" action="{{ route('admin.login.submit') }}">
      @csrf
      <div class="field">
        <label for="email">Email Address</label>
        <input class="input" id="email" name="email" type="email" value="{{ old('email') }}" placeholder="Enter your email" autocomplete="username" maxlength="255" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input class="input" id="password" name="password" type="password" placeholder="Enter your password" autocomplete="current-password" required>
      </div>
      <label class="check login-remember"><input name="remember" value="1" type="checkbox"> Keep me signed in</label>
      <button class="btn btn-primary login-submit" type="submit">Sign in <x-admin.icon name="arrow-right" /></button>
    </form>
  </section>
  <p class="login-help"><x-admin.icon name="lock" /> Authorized platform administrators only.</p>
</main>
</body>
</html>
