<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Administrator Login | Sign2Digital</title>
  <link rel="stylesheet" href="{{ asset('admin-assets/admin.css') }}">
</head>
<body class="login-page">
  <main class="login-panel">
    <div class="brand"><img class="brand-logo" src="{{ asset('admin-assets/logo.png') }}" alt="Sign2Digital"></div>
    <h1>Welcome back</h1>
    <p>Sign in to manage your Sign2Digital platform. Access is restricted to authorized administrators.</p>
    @if ($errors->any())
      <div class="alert error" role="alert">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login.submit') }}">
      @csrf
      <div class="field"><label for="email">Administrator email</label><input class="input" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" maxlength="255" required autofocus></div>
      <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <label class="check"><input name="remember" value="1" type="checkbox"> Remember this device</label>
      <button class="btn btn-primary" type="submit">Sign in securely</button>
    </form>
    <p class="hint" style="margin:24px 0 0">For access, contact the platform owner. Administrator accounts are provisioned securely.</p>
  </main>
</body>
</html>
