{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title') · {{ config('app.name') }}</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('app.css') }}">
</head>
<body>
@auth
<header class="topbar">
  <div class="wrap">
    <a class="brand" href="{{ route('staff.home') }}">{{ config('app.name') }}</a>
    <nav aria-label="Main">
      @can('users.manage')<a href="{{ route('users.index') }}">Users</a>@endcan
      <a href="{{ route('password.edit') }}">Password</a>
    </nav>
    <form method="post" action="{{ route('logout') }}">
      @csrf
      <button type="submit" class="btn-link">Sign out ({{ auth()->user()->name }})</button>
    </form>
  </div>
</header>
@endauth
<main class="wrap">
  @if (session('status'))
    <div class="notice notice-ok" role="status">{{ session('status') }}</div>
  @endif
  @yield('content')
</main>
</body>
</html>
