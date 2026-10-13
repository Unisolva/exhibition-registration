{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Settings')

@section('content')
<div class="narrow">
  <h1>Settings</h1>
  <p class="actions"><a href="{{ route('settings.branding') }}">Branding</a> <a href="{{ route('fields.index') }}">Form fields</a></p>

  <form method="post" action="{{ route('settings.general.update') }}" class="card" novalidate>
    @csrf
    @method('PUT')

    <h2>Public registration</h2>
    <label class="check">
      <input type="checkbox" name="public_registration_enabled" value="1" @checked(old('public_registration_enabled', $settings->get('public_registration_enabled')))>
      The public QR form accepts registrations
    </label>
    <p class="hint">Switch off to stop every public form at once. Staff can still enter visitors. To stop one exhibition only, close it.</p>

    <h2>Region and time</h2>
    <div class="field">
      <label for="default_country">Default country <span class="hint">(phone numbers and the region list)</span></label>
      <select id="default_country" name="default_country">
        @foreach ($countries as $country)
          <option value="{{ $country }}" @selected(old('default_country', $settings->get('default_country')) === $country)>{{ $country }}</option>
        @endforeach
      </select>
      @error('default_country')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="display_timezone">Display time zone</label>
      <select id="display_timezone" name="display_timezone">
        @foreach ($timezones as $zone)
          <option value="{{ $zone }}" @selected(old('display_timezone', $settings->get('display_timezone')) === $zone)>{{ $zone }}</option>
        @endforeach
      </select>
      @error('display_timezone')<div class="error">{{ $message }}</div>@enderror
    </div>

    <h2>Retention</h2>
    <div class="field">
      <label for="retention_months">Keep visitor details for (months)</label>
      <input id="retention_months" name="retention_months" type="number" min="1" max="120" required value="{{ old('retention_months', $settings->get('retention_months')) }}">
      @error('retention_months')<div class="error">{{ $message }}</div>@enderror
      <p class="hint">Older visitors are deleted for good by the nightly housekeeping, even if they were sent to the ERP. Visitors still being sent wait until the push finishes.</p>
    </div>

    <div class="actions"><button type="submit" class="btn">Save</button></div>
  </form>
</div>
@endsection
