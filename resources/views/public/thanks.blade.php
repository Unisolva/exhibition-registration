{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- No link back to the form (M9 decision 10). With a welcome package, this visitor's code and QR (M9 CO-6). --}}
@extends('layouts.public')

@section('title', __('public.thanks_title'))

@section('content')
<div class="card" role="status">
  <h1>{{ __('public.thanks_title') }}</h1>
  @if ($packageCode)
    <p>{{ __('public.thanks_package', ['exhibition' => $exhibition->name]) }}</p>
    <div class="package-box">
      @if (($note = $exhibition->packageNote(app()->getLocale())) !== '')
        <p class="package-note">{!! nl2br(e($note)) !!}</p>
      @endif
      <div class="package-qr" role="img" aria-label="{{ __('public.package_code_label') }}: {{ $packageCode }}">{!! $packageQr !!}</div>
      <p class="package-code" dir="ltr" lang="en"><span class="visually-hidden">{{ __('public.package_code_label') }}: </span>{{ $packageCode }}</p>
      <p class="package-show">{{ __('public.package_show') }}</p>
    </div>
  @else
    <p>{{ __('public.thanks_body', ['exhibition' => $exhibition->name]) }}</p>
  @endif
</div>
@endsection
