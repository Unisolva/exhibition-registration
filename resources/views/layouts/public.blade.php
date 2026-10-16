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
      {{-- The organisation's name follows, so the logo needs no text of its own (M8 CO-14). --}}
      <img class="logo" src="{{ $logo }}" alt=""@if ($size = $branding->logoSize()) width="{{ $size[0] }}" height="{{ $size[1] }}"@endif>
    @endif
    <span class="org">{{ $branding->orgName($locale) }}</span>
    <a class="lang" href="{{ url()->current() }}?lang={{ __('public.switch_language_code') }}"
       lang="{{ __('public.switch_language_code') }}" hreflang="{{ __('public.switch_language_code') }}">{{ __('public.switch_language') }}</a>
  </div>
</header>
<main class="wrap narrow">
  @isset($exhibition)
    @php($dates = $exhibition->starts_on->locale($locale)->translatedFormat('j F Y')
        .($exhibition->ends_on && ! $exhibition->ends_on->equalTo($exhibition->starts_on) ? ' – '.$exhibition->ends_on->locale($locale)->translatedFormat('j F Y') : ''))
    {{-- On the form the exhibition name is the page heading (M8 CO-14); other pages have their own. --}}
    @hasSection('exhibition-heading')
      <h1 class="exhibition-title">{{ $exhibition->name }}</h1>
      <p class="exhibition-dates hint">{{ $dates }}</p>
    @else
      <p class="exhibition"><strong>{{ $exhibition->name }}</strong><br><span class="hint">{{ $dates }}</span></p>
    @endif
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
