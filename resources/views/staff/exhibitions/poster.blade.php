{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- A4 poster: company logo and name, exhibition name and dates, a call to scan, the QR code and the
     short URL in large type. Bilingual text puts the exhibition's default language first (M8 CO-8, CO-15). --}}
@inject('branding', 'App\Support\Branding')
@php($first = $exhibition->default_locale)
@php($second = $first === 'ar' ? 'en' : 'ar')
@php($dir = fn (string $locale) => $locale === 'ar' ? 'rtl' : 'ltr')
@php($dates = fn (string $locale) => $exhibition->starts_on->locale($locale)->translatedFormat('j F Y')
    .($exhibition->ends_on && ! $exhibition->ends_on->equalTo($exhibition->starts_on) ? ' – '.$exhibition->ends_on->locale($locale)->translatedFormat('j F Y') : ''))
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Poster · {{ $exhibition->name }}</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('app.css') }}">
</head>
<body class="poster">
<main class="poster-page">
  @if ($logo = $branding->logoUrl())
    <img class="poster-logo" src="{{ $logo }}" alt=""@if ($size = $branding->logoSize()) width="{{ $size[0] }}" height="{{ $size[1] }}"@endif>
  @endif
  <p class="poster-org" lang="{{ $first }}" dir="{{ $dir($first) }}">{{ $branding->orgName($first) }}</p>
  @if ($branding->orgName($second) !== $branding->orgName($first))
    <p class="poster-org-second" lang="{{ $second }}" dir="{{ $dir($second) }}">{{ $branding->orgName($second) }}</p>
  @endif
  <h1 class="poster-title">{{ $exhibition->name }}</h1>
  <p class="poster-dates" lang="{{ $first }}" dir="{{ $dir($first) }}">{{ $dates($first) }}</p>
  <p class="poster-cta" lang="{{ $first }}" dir="{{ $dir($first) }}">{{ __('public.poster_cta', [], $first) }}</p>
  <p class="poster-cta-second" lang="{{ $second }}" dir="{{ $dir($second) }}">{{ __('public.poster_cta', [], $second) }}</p>
  <div class="poster-qr">{!! $qr !!}</div>
  <p class="poster-url">{{ preg_replace('#^https?://#', '', $exhibition->publicUrl()) }}</p>
  <div class="no-print poster-tools">
    <button type="button" class="btn" data-print>Print</button>
    <a href="{{ route('exhibitions.show', $exhibition) }}">Back to the exhibition</a>
    <p class="hint">A4, portrait. Without the button, use your browser's Print command.</p>
  </div>
</main>
<script src="{{ \App\Support\Asset::url('staff.js') }}" defer></script>
</body>
</html>
