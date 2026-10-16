{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Error pages show Arabic and English together: the error may come before any language is chosen.
     One link back (M8 L4): the staff home for staff URLs, the registration form when the URL holds a valid token. --}}
@php
    $back = null;
    $path = request()->path();
    if (str_starts_with($path, 'staff')) {
        // Relative links: an error page may answer a request for an unknown host (no URL is built from it).
        $back = ['url' => '/staff', 'key' => 'errors.back_staff'];
    } elseif (preg_match('#^r/([^/]+)#', $path, $m) === 1 && $code !== '503') {
        try {
            $found = \App\Models\Exhibition::findByPublicToken($m[1]);
            $back = $found ? ['url' => '/r/'.$found->public_token, 'key' => 'errors.back_form'] : null;
        } catch (\Throwable) {
            $back = null;
        }
    }
@endphp
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ __("errors.{$code}.title", [], 'ar') }} · {{ __("errors.{$code}.title", [], 'en') }}</title>
<link rel="stylesheet" href="{{ \App\Support\Asset::url('app.css') }}">
</head>
<body>
<main class="wrap narrow">
  <div class="card">
    <section class="lang-block" lang="ar" dir="rtl">
      <h1>{{ __("errors.{$code}.title", [], 'ar') }}</h1>
      <p>{{ __("errors.{$code}.body", [], 'ar') }}</p>
      @if ($back)<p><a href="{{ $back['url'] }}">{{ __($back['key'], [], 'ar') }}</a></p>@endif
    </section>
    <section class="lang-block" lang="en" dir="ltr">
      <h1>{{ __("errors.{$code}.title", [], 'en') }}</h1>
      <p>{{ __("errors.{$code}.body", [], 'en') }}</p>
      @if ($back)<p><a href="{{ $back['url'] }}">{{ __($back['key'], [], 'en') }}</a></p>@endif
    </section>
  </div>
</main>
</body>
</html>
