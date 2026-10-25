{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($editing = $exhibition->exists)

@section('title', $editing ? 'Edit exhibition' : 'Add exhibition')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">{{ $editing ? 'Edit exhibition' : 'Add exhibition' }}</h1></div>
  <form method="post" action="{{ $editing ? route('exhibitions.update', $exhibition) : route('exhibitions.store') }}" class="card card-body" novalidate>
    @csrf
    @if ($editing) @method('PUT') @endif

    <x-field name="name" label="Name" :value="old('name', $exhibition->name)" required maxlength="150" />
    <x-field type="date" name="starts_on" label="First day" :value="old('starts_on', $exhibition->starts_on?->format('Y-m-d'))" required />
    <x-field type="date" name="ends_on" label="Last day (optional)" :value="old('ends_on', $exhibition->ends_on?->format('Y-m-d'))" />
    <x-field type="select" name="default_locale" label="Public form language" :value="old('default_locale', $exhibition->default_locale ?? 'ar')"
             :options="['ar' => 'Arabic', 'en' => 'English']" hint="The language the registration page opens in. Visitors can still switch." />

    {{-- M9 CO-4: the notes show while the box is ticked (without JavaScript always). --}}
    <x-field type="checkbox" name="offers_package" label="Offers a welcome package" data-package-toggle
             :checked="(bool) old('offers_package', $exhibition->offers_package)"
             hint="Visitors who register by QR see a package code and a QR on the thank-you page, to show to your team." />
    <div class="package-notes" data-package-notes>
      <x-field type="textarea" name="package_note_ar" label="Package note in Arabic" :value="old('package_note_ar', $exhibition->package_note_ar)"
               maxlength="500" dir="rtl" lang="ar" rows="3" hint="Shown above the code. Plain text, at most 500 characters." />
      <x-field type="textarea" name="package_note_en" label="Package note in English" :value="old('package_note_en', $exhibition->package_note_en)"
               maxlength="500" rows="3" hint="If one language is empty, the other is shown." />
    </div>
    @if ($editing)
      <p class="hint">Renaming keeps the same registration link and QR code.</p>
    @endif
    <div class="actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="{{ $editing ? route('exhibitions.show', $exhibition) : route('exhibitions.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
