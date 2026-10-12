{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- A4 poster: company logo, exhibition name, QR code and the short URL in large type. --}}
@inject('branding', 'App\Support\Branding')
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
    <img class="poster-logo" src="{{ $logo }}" alt="{{ $branding->orgName('en') }}">
  @else
    <p class="poster-org">{{ $branding->orgName('ar') }}</p>
  @endif
  <h1 class="poster-title">{{ $exhibition->name }}</h1>
  <div class="poster-qr">{!! $qr !!}</div>
  <p class="poster-url">{{ preg_replace('#^https?://#', '', $exhibition->publicUrl()) }}</p>
  <p class="no-print"><a href="{{ route('exhibitions.show', $exhibition) }}">Back</a> · Use your browser's Print command (A4, portrait).</p>
</main>
</body>
</html>
