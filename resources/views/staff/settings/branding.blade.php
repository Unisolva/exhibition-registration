{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Branding')

@section('content')
<div class="narrow">
  <h1>Branding</h1>
  <form method="post" action="{{ route('settings.branding.update') }}" enctype="multipart/form-data" class="card" novalidate>
    @csrf
    @method('PUT')

    <div class="field">
      <label for="org_name_en">Company name (English)</label>
      <input id="org_name_en" name="org_name_en" type="text" maxlength="100" value="{{ old('org_name_en', $settings->get('org_name_en')) }}">
      @error('org_name_en')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="org_name_ar">Company name (Arabic)</label>
      <input id="org_name_ar" name="org_name_ar" type="text" maxlength="100" dir="rtl" lang="ar" value="{{ old('org_name_ar', $settings->get('org_name_ar')) }}">
      @error('org_name_ar')<div class="error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="logo">Logo <span class="hint">(PNG or SVG, at most 100 KB)</span></label>
      @if ($logoUrl)
        <p><img src="{{ $logoUrl }}" alt="Current logo" class="logo-preview"></p>
      @endif
      <input id="logo" name="logo" type="file" accept="image/png,image/svg+xml"
             @error('logo') aria-invalid="true" aria-describedby="logo-error" @enderror>
      @error('logo')<div class="error" id="logo-error">{{ $message }}</div>@enderror
      @if ($logoUrl)
        <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
      @endif
    </div>

    <div class="field">
      <label for="brand_color">Brand colour <span class="hint">(hex, e.g. #1F4E79; white text on it must reach 4.5:1 contrast)</span></label>
      <input id="brand_color" name="brand_color" type="text" required maxlength="7" dir="ltr" value="{{ old('brand_color', $settings->get('brand_color')) }}"
             @error('brand_color') aria-invalid="true" aria-describedby="brand_color-error" @enderror>
      @error('brand_color')<div class="error" id="brand_color-error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="privacy_url">Privacy page link <span class="hint">(optional, https)</span></label>
      <input id="privacy_url" name="privacy_url" type="url" maxlength="255" dir="ltr" value="{{ old('privacy_url', $settings->get('privacy_url')) }}"
             @error('privacy_url') aria-invalid="true" aria-describedby="privacy_url-error" @enderror>
      @error('privacy_url')<div class="error" id="privacy_url-error">{{ $message }}</div>@enderror
    </div>

    <label class="check"><input type="checkbox" name="show_powered_by" value="1" @checked(old('show_powered_by', $settings->get('show_powered_by')))> Show "Powered by Unisolva" on public pages</label>

    <div class="actions"><button type="submit" class="btn">Save</button></div>
  </form>
</div>
@endsection
