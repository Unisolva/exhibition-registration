{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Branding')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Branding</h1></div>
  <form method="post" action="{{ route('settings.branding.update') }}" enctype="multipart/form-data" class="card card-body" novalidate>
    @csrf
    @method('PUT')

    <x-field name="org_name_en" label="Company name (English)" :value="old('org_name_en', $settings->get('org_name_en'))" maxlength="100" />
    <x-field name="org_name_ar" label="Company name (Arabic)" :value="old('org_name_ar', $settings->get('org_name_ar'))" maxlength="100" dir="rtl" lang="ar" />

    @if ($logoUrl)
      <p><img src="{{ $logoUrl }}" alt="Current logo" class="logo-preview"></p>
    @endif
    <x-field type="file" name="logo" label="Logo" hint="PNG or SVG, at most 100 KB." accept="image/png,image/svg+xml" />
    @if ($logoUrl)
      <x-field type="checkbox" name="remove_logo" label="Remove the current logo" />
    @endif

    <x-field name="brand_color" label="Brand colour" hint="Hex, e.g. #1F4E79. White text on it must reach 4.5:1 contrast."
             :value="old('brand_color', $settings->get('brand_color'))" required maxlength="7" dir="ltr" />
    {{-- M11 CO-1: the gift box colour; the text colour on it is chosen automatically. --}}
    <x-field name="accent_color" label="Accent colour (optional)"
             hint="Fills the gift box on the thank-you page. Leave empty to keep the dashed brand-colour border."
             :value="old('accent_color', $settings->get('accent_color'))" maxlength="7" dir="ltr" />
    @if ($accent)
      <style nonce="{{ $cspNonce }}">.accent-preview{background:{{ $accent['color'] }};color:{{ $accent['ink'] }}}</style>
      <p class="accent-preview">Gift box preview: {{ $accent['color'] }} with {{ $accent['ink'] === \App\Support\Branding::INK_DARK ? 'dark' : 'white' }} text</p>
    @endif
    <x-field type="url" name="privacy_url" label="Privacy page link (optional)" hint="An https address."
             :value="old('privacy_url', $settings->get('privacy_url'))" maxlength="255" dir="ltr" />
    <x-field type="checkbox" name="show_powered_by" label='Show "Powered by Unisolva" on public pages'
             :checked="(bool) old('show_powered_by', $settings->get('show_powered_by'))" />

    <div class="actions"><button type="submit" class="btn btn-primary">Save</button></div>
  </form>
</div>
@endsection
