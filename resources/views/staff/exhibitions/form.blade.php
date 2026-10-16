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

    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" required maxlength="150" value="{{ old('name', $exhibition->name) }}"
             @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
      @error('name')<div class="error" id="name-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="starts_on">First day</label>
      <input id="starts_on" name="starts_on" type="date" required value="{{ old('starts_on', $exhibition->starts_on?->format('Y-m-d')) }}"
             @error('starts_on') aria-invalid="true" aria-describedby="starts_on-error" @enderror>
      @error('starts_on')<div class="error" id="starts_on-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="ends_on">Last day <span class="hint">(optional)</span></label>
      <input id="ends_on" name="ends_on" type="date" value="{{ old('ends_on', $exhibition->ends_on?->format('Y-m-d')) }}"
             @error('ends_on') aria-invalid="true" aria-describedby="ends_on-error" @enderror>
      @error('ends_on')<div class="error" id="ends_on-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="default_locale">Public form language</label>
      <select id="default_locale" name="default_locale" aria-describedby="default_locale-hint @error('default_locale') default_locale-error @enderror"
              @error('default_locale') aria-invalid="true" @enderror>
        @foreach (['ar' => 'Arabic', 'en' => 'English'] as $value => $label)
          <option value="{{ $value }}" @selected(old('default_locale', $exhibition->default_locale ?? 'ar') === $value)>{{ $label }}</option>
        @endforeach
      </select>
      <div class="hint" id="default_locale-hint">The language the registration page opens in. Visitors can still switch.</div>
      @error('default_locale')<div class="error" id="default_locale-error">{{ $message }}</div>@enderror
    </div>
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
