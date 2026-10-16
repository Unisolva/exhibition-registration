{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Settings')

@section('content')
<div class="narrow">
  <h1>Settings</h1>
  <form method="post" action="{{ route('settings.general.update') }}" class="card" novalidate>
    @csrf
    @method('PUT')

    <h2>Public registration</h2>
    <x-field type="checkbox" name="public_registration_enabled" label="The public QR form accepts registrations"
             :checked="(bool) old('public_registration_enabled', $settings->get('public_registration_enabled'))"
             hint="Switch off to stop every public form at once. Staff can still enter visitors. To stop one exhibition only, close it." />

    <h2>Region and time</h2>
    <x-field type="select" name="default_country" label="Default country" hint="Used for phone numbers and the region list."
             :value="old('default_country', $settings->get('default_country'))" :options="collect($countries)->mapWithKeys(fn ($c) => [$c => $c])->all()" />
    <x-field type="select" name="display_timezone" label="Display time zone"
             :value="old('display_timezone', $settings->get('display_timezone'))" :options="collect($timezones)->mapWithKeys(fn ($z) => [$z => $z])->all()" />

    <h2>Retention</h2>
    <x-field type="number" name="retention_months" label="Keep visitor details for (months)" min="1" max="120" required
             :value="old('retention_months', $settings->get('retention_months'))"
             hint="Older visitors are deleted for good by the nightly housekeeping, even if they were sent to the ERP. Visitors still being sent wait until sending has finished." />

    <div class="actions"><button type="submit" class="btn">Save</button></div>
  </form>
</div>
@endsection
