{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Error pages show Arabic and English together: the error may come before any language is chosen. --}}
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
    </section>
    <section class="lang-block" lang="en" dir="ltr">
      <h1>{{ __("errors.{$code}.title", [], 'en') }}</h1>
      <p>{{ __("errors.{$code}.body", [], 'en') }}</p>
    </section>
  </div>
</main>
</body>
</html>
