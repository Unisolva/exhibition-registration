{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Staff layout on the Tabler UI kit (M10 CO-2): a sidebar from 992 px, a compact top bar with Save & New,
     Packages and a menu button on phones. Without JavaScript the menu is simply open. --}}
@inject('branding', 'App\Support\Branding')
@php
    $user = auth()->user();
    $activeErp = $user ? \App\Models\ErpConnection::active() : null;
    // The forced password change page shows no links that would only bounce back to it (M8 CO-13).
    $showNav = $user && ! $user->must_change_password;
    $groups = [];
    if ($showNav) {
        // M9 CO-9: "Packages" for managers always, for staff when the exhibition they work at offers one.
        $sessionExhibitionId = session(\App\Http\Controllers\Staff\EntryController::SESSION_KEY);
        $showPackages = $user->isManager()
            || ($sessionExhibitionId && \App\Models\Exhibition::query()->whereKey($sessionExhibitionId)->value('offers_package'));
        $odooMode = app(\App\Support\Settings::class)->get('erp_mode') === 'odoo';
        // [label, url, route patterns that mark it as the current page, icon]
        $groups = array_filter([
            'Event' => array_values(array_filter([
                ['Save & New', route('staff.entry'), ['staff.entry'], 'user-plus'],
                $showPackages ? ['Packages', route('packages.index'), ['packages.*'], 'gift'] : null,
                [$user->isManager() ? 'Visitors' : 'My entries', route('visitors.index'), ['visitors.*'], 'users'],
            ])),
            'Manage' => array_values(array_filter([
                $user->can('exhibitions.manage') ? ['Exhibitions', route('exhibitions.index'), ['exhibitions.*'], 'building-store'] : null,
                $user->can('users.manage') ? ['Users', route('users.index'), ['users.*'], 'user-cog'] : null,
                $user->can('registrations.view-all') ? ['Trash', route('trash.index'), ['trash.*'], 'trash'] : null,
                $odooMode && $user->can('registrations.push') ? ['Batches', route('batches.index'), ['batches.*'], 'send'] : null,
            ])),
            'Settings' => $user->can('system.manage') ? [
                ['Settings', route('settings.general'), ['settings.general', 'settings.general.*'], 'settings'],
                ['Branding', route('settings.branding'), ['settings.branding', 'settings.branding.*'], 'palette'],
                ['Form fields', route('fields.index'), ['fields.*'], 'forms'],
                ['ERP', route('erp.show'), ['erp.*'], 'plug-connected'],
                ['System', route('system'), ['system'], 'server'],
            ] : [],
        ]);
        // Beside the menu button on phones (M9 §3a.7).
        $shortcuts = array_slice($groups['Event'], 0, $showPackages ? 2 : 1);
    }
    $current = fn (array $patterns) => request()->routeIs(...$patterns);
    $role = $user ? ($user->isSuperAdmin() ? 'Super admin' : ($user->isAdmin() ? 'Admin' : 'Staff')) : null;
    $brand = $branding->color();
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title') · {{ config('app.name') }}</title>
<script nonce="{{ $cspNonce }}">document.documentElement.classList.add('js');</script>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('vendor/tabler/tabler.min.css') }}">
<link rel="stylesheet" href="{{ \App\Support\Asset::url('staff.css') }}">
{{-- The installation's brand colour is the primary colour, as on the public pages; its contrast with white is checked at save. --}}
<style nonce="{{ $cspNonce }}">:root{--tblr-primary:{{ $brand }};--tblr-primary-rgb:{{ implode(',', sscanf($brand, '#%02x%02x%02x')) }};--brand:{{ $brand }}}</style>
</head>
<body class="staff{{ $activeErp?->kind === 'test' ? ' has-test-banner' : '' }}">
<a class="skip-link" href="#main">Skip to content</a>
@if ($activeErp?->kind === 'test')
  <div class="test-banner" role="region" aria-label="Test ERP">TEST ERP: leads go to {{ $activeErp->base_url }} / {{ $activeErp->database }}</div>
@endif
@auth
<aside class="navbar navbar-vertical navbar-expand-lg staff-nav" data-bs-theme="dark">
  <div class="container-fluid">
    <div class="staff-topbar">
      <a class="navbar-brand staff-brand" href="{{ route('staff.home') }}">{{ $branding->orgName('en') }}</a>
      @if ($showNav)
        <div class="staff-shortcuts d-lg-none">
          @foreach ($shortcuts as [$label, $url, $patterns])
            <a class="btn btn-primary" href="{{ $url }}" @if ($current($patterns)) aria-current="page" @endif>{{ $label }}</a>
          @endforeach
        </div>
      @endif
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#staff-menu" aria-controls="staff-menu" aria-expanded="false">
        <x-icon name="menu-2" /><span class="visually-hidden">Menu</span>
      </button>
    </div>
    <div class="collapse navbar-collapse" id="staff-menu">
      @if ($showNav)
        <nav class="staff-menu" aria-label="Main">
          @foreach ($groups as $title => $links)
            <h2 class="nav-section-title">{{ $title }}</h2>
            <ul class="navbar-nav">
              @foreach ($links as [$label, $url, $patterns, $icon])
                <li class="nav-item{{ $current($patterns) ? ' active' : '' }}">
                  <a class="nav-link" href="{{ $url }}" @if ($current($patterns)) aria-current="page" @endif><span class="nav-link-icon"><x-icon :name="$icon" /></span><span class="nav-link-title">{{ $label }}</span></a>
                </li>
              @endforeach
            </ul>
          @endforeach
        </nav>
      @endif
      <div class="staff-user">
        <p class="staff-user-name">{{ $user->name }} <span class="staff-user-role">{{ $role }}</span></p>
        <ul class="navbar-nav">
          @if ($showNav)
            <li class="nav-item{{ $current(['password.*']) ? ' active' : '' }}">
              <a class="nav-link" href="{{ route('password.edit') }}" @if ($current(['password.*'])) aria-current="page" @endif><span class="nav-link-icon"><x-icon name="key" /></span><span class="nav-link-title">Password</span></a>
            </li>
          @endif
          <li class="nav-item">
            <form method="post" action="{{ route('logout') }}">
              @csrf
              <button type="submit" class="nav-link staff-signout"><span class="nav-link-icon"><x-icon name="logout" /></span><span class="nav-link-title">Sign out</span></button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
</aside>
@endauth
<div class="page">
  <div class="page-wrapper">
    <main id="main" class="page-body" tabindex="-1">
      <div class="container-xl">
        @if (session('status'))
          <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if (session('warning'))
          <div class="alert alert-warning" role="status">{{ session('warning') }}</div>
        @endif
        @yield('content')
      </div>
    </main>
  </div>
</div>
<script src="{{ \App\Support\Asset::url('vendor/tabler/tabler.min.js') }}" defer></script>
<script src="{{ \App\Support\Asset::url('staff.js') }}" defer></script>
@stack('scripts')
</body>
</html>
