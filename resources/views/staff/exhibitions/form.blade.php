{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($editing = $exhibition->exists)

@section('title', $editing ? 'Edit exhibition' : 'Add exhibition')

@section('content')
<div class="narrow">
  <h1>{{ $editing ? 'Edit exhibition' : 'Add exhibition' }}</h1>
  <form method="post" action="{{ $editing ? route('exhibitions.update', $exhibition) : route('exhibitions.store') }}" class="card" novalidate>
    @csrf
    @if ($editing) @method('PUT') @endif

    <x-field name="name" label="Name" :value="old('name', $exhibition->name)" required maxlength="150" />
    <x-field type="date" name="starts_on" label="First day" :value="old('starts_on', $exhibition->starts_on?->format('Y-m-d'))" required />
    <x-field type="date" name="ends_on" label="Last day (optional)" :value="old('ends_on', $exhibition->ends_on?->format('Y-m-d'))" />
    <x-field type="select" name="default_locale" label="Public form language" :value="old('default_locale', $exhibition->default_locale ?? 'ar')"
             :options="['ar' => 'Arabic', 'en' => 'English']" hint="The language the registration page opens in. Visitors can still switch." />
    @if ($editing)
      <p class="hint">Renaming keeps the same registration link and QR code.</p>
    @endif
    <div class="actions">
      <button type="submit" class="btn">Save</button>
      <a href="{{ $editing ? route('exhibitions.show', $exhibition) : route('exhibitions.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
