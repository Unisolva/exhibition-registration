{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@inject('branding', 'App\Support\Branding')
@php($locale = app()->getLocale())
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>@yield('title') · {{ $branding->orgName($locale) }}</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('app.css') }}">
<style nonce="{{ $cspNonce }}">:root{--brand:{{ $branding->color() }}}</style>
</head>
<body class="public">
<header class="public-head">
  <div class="wrap narrow public-head-inner">
    @if ($logo = $branding->logoUrl())
      <img class="logo" src="{{ $logo }}" alt="{{ $branding->orgName($locale) }}">
    @endif
    <span class="org">{{ $branding->orgName($locale) }}</span>
    <a class="lang" href="{{ url()->current() }}?lang={{ __('public.switch_language_code') }}"
       lang="{{ __('public.switch_language_code') }}" hreflang="{{ __('public.switch_language_code') }}">{{ __('public.switch_language') }}</a>
  </div>
</header>
<main class="wrap narrow">
  @isset($exhibition)
    <p class="exhibition">
      <strong>{{ $exhibition->name }}</strong><br>
      <span class="hint">
        {{ $exhibition->starts_on->locale($locale)->translatedFormat('j F Y') }}
        @if ($exhibition->ends_on && ! $exhibition->ends_on->equalTo($exhibition->starts_on))
          – {{ $exhibition->ends_on->locale($locale)->translatedFormat('j F Y') }}
        @endif
      </span>
    </p>
  @endisset
  @yield('content')
</main>
<footer class="wrap narrow public-foot">
  @if ($privacy = $branding->privacyUrl())
    <a href="{{ $privacy }}" rel="noopener">{{ __('public.privacy') }}</a>
  @endif
  @if ($branding->showPoweredBy())
    <a href="https://unisolva.com/" rel="noopener" lang="en" dir="ltr">{{ __('public.powered_by') }}</a>
  @endif
</footer>
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
</body>
</html>
