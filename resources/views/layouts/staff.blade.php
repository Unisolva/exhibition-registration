{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title') · {{ config('app.name') }}</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('app.css') }}">
</head>
<body>
@auth
@php
    $user = auth()->user();
    $activeErp = \App\Models\ErpConnection::active();
    // The forced password change page shows no links that would only bounce back to it (M8 CO-13).
    $showNav = ! $user->must_change_password;
    // [label, url, route patterns that mark it as the current page]
    $links = array_values(array_filter([
        ['Save & New', route('staff.entry'), ['staff.entry']],
        [$user->isManager() ? 'Visitors' : 'My entries', route('visitors.index'), ['visitors.*']],
        $user->can('exhibitions.manage') ? ['Exhibitions', route('exhibitions.index'), ['exhibitions.*']] : null,
        app(\App\Support\Settings::class)->get('erp_mode') === 'odoo' && $user->can('registrations.push') ? ['Batches', route('batches.index'), ['batches.*']] : null,
        $user->can('registrations.view-all') ? ['Trash', route('trash.index'), ['trash.*']] : null,
        $user->can('users.manage') ? ['Users', route('users.index'), ['users.*']] : null,
        $user->can('system.manage') ? ['Settings', route('settings.general'), ['settings.*', 'fields.*', 'erp.*', 'system']] : null,
        ['Password', route('password.edit'), ['password.*']],
    ]));
    $current = fn (array $patterns) => request()->routeIs(...$patterns);
@endphp
<header class="topbar">
  @if ($activeErp?->kind === 'test')
    <div class="test-banner" role="note">TEST ERP: leads go to {{ $activeErp->base_url }} / {{ $activeErp->database }}</div>
  @endif
  <div class="wrap">
    <a class="brand" href="{{ route('staff.home') }}">{{ config('app.name') }}</a>
    @if ($showNav)
      {{-- Wide screens: every link. --}}
      <nav class="nav-wide" aria-label="Main">
        @foreach ($links as [$label, $url, $patterns])
          <a href="{{ $url }}" @if ($current($patterns)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
      {{-- Under 40rem: Save & New, and the rest in a menu (no script needed). --}}
      <nav class="nav-narrow" aria-label="Main">
        <a href="{{ $links[0][1] }}" @if ($current($links[0][2])) aria-current="page" @endif>{{ $links[0][0] }}</a>
        <details class="menu">
          <summary>Menu</summary>
          <div class="menu-links">
            @foreach (array_slice($links, 1) as [$label, $url, $patterns])
              <a href="{{ $url }}" @if ($current($patterns)) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            <form method="post" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="btn-link">Sign out ({{ $user->name }})</button>
            </form>
          </div>
        </details>
      </nav>
    @endif
    <form method="post" action="{{ route('logout') }}" class="{{ $showNav ? 'nav-wide' : '' }}">
      @csrf
      <button type="submit" class="btn-link">Sign out ({{ $user->name }})</button>
    </form>
  </div>
</header>
@endauth
<main class="wrap">
  @auth
    @if (request()->routeIs('settings.*', 'fields.*', 'erp.*', 'system'))
      <nav class="subnav" aria-label="Settings">
        @foreach ([['Settings', 'settings.general', ['settings.general', 'settings.general.*']], ['Branding', 'settings.branding', ['settings.branding', 'settings.branding.*']], ['Form fields', 'fields.index', ['fields.*']], ['ERP', 'erp.show', ['erp.*']], ['System', 'system', ['system']]] as [$label, $route, $patterns])
          <a href="{{ route($route) }}" @if (request()->routeIs(...$patterns)) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
      </nav>
    @endif
  @endauth
  @if (session('status'))
    <div class="notice notice-ok" role="status">{{ session('status') }}</div>
  @endif
  @if (session('warning'))
    <div class="notice notice-warn" role="status">{{ session('warning') }}</div>
  @endif
  @yield('content')
</main>
<script src="{{ \App\Support\Asset::url('staff.js') }}" defer></script>
@stack('scripts')
</body>
</html>
