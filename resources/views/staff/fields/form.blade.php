{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($editing = $field->exists)
@php($alwaysOn = in_array($field->key, ['name', 'phone'], true))

@section('title', $editing ? 'Edit field' : 'Add field')

@section('content')
<div class="narrow">
  <h1>{{ $editing ? 'Edit field: '.$field->label_en : 'Add a field' }}</h1>
  <form method="post" action="{{ $editing ? route('fields.update', $field) : route('fields.store') }}" class="card" novalidate>
    @csrf
    @if ($editing) @method('PUT') @endif

    @if ($editing)
      <p>Key <code>{{ $field->key }}</code> · type {{ $field->type }}@if ($field->is_system) · system field @endif <span class="hint">(key and type never change)</span></p>
    @else
      <div class="field">
        <label for="key">Key <span class="hint">(lower-case letters, digits and _, starting with a letter; cannot be changed later)</span></label>
        <input id="key" name="key" type="text" required maxlength="40" dir="ltr" value="{{ old('key') }}">
        @error('key')<div class="error">{{ $message }}</div>@enderror
      </div>
      <div class="field">
        <label for="type">Type</label>
        <select id="type" name="type">
          <option value="text" @selected(old('type', 'text') === 'text')>Short text</option>
          <option value="select" @selected(old('type') === 'select')>List of choices</option>
        </select>
        @error('type')<div class="error">{{ $message }}</div>@enderror
      </div>
    @endif

    <div class="field">
      <label for="label_en">Label in English</label>
      <input id="label_en" name="label_en" type="text" required maxlength="100" value="{{ old('label_en', $field->label_en) }}">
      @error('label_en')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="label_ar">Label in Arabic</label>
      <input id="label_ar" name="label_ar" type="text" required maxlength="100" dir="rtl" lang="ar" value="{{ old('label_ar', $field->label_ar) }}">
      @error('label_ar')<div class="error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="sort_order">Order <span class="hint">(lower comes first)</span></label>
      <input id="sort_order" name="sort_order" type="number" min="0" max="100000" required value="{{ old('sort_order', $field->sort_order) }}">
      @error('sort_order')<div class="error">{{ $message }}</div>@enderror
    </div>

    @if ($alwaysOn)
      <p class="hint">Name and phone are always active and required.</p>
    @else
      <label class="check"><input type="checkbox" name="is_required" value="1" @checked(old('is_required', $field->is_required))> Required</label>
      <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $field->is_active))> Active (shown on the forms)</label>
      @error('is_active')<div class="error">{{ $message }}</div>@enderror
    @endif
    <label class="check"><input type="checkbox" name="send_to_erp" value="1" @checked(old('send_to_erp', $field->send_to_erp))> Send to the ERP with the lead</label>

    @if (! $editing || $field->type === 'select')
      <h2>Choices</h2>
      <p class="hint">For a list of choices. A list with no active choices is shown as a free-text box. Choice values cannot change once added; deactivate a choice to hide it.</p>
      @error('new_options')<div class="error">{{ $message }}</div>@enderror
      @foreach ($field->options ?? [] as $i => $option)
        <fieldset class="option-row">
          <legend><code>{{ $option['value'] }}</code></legend>
          <label for="opt-{{ $i }}-en">English</label>
          <input id="opt-{{ $i }}-en" name="options[{{ $i }}][label_en]" type="text" maxlength="100" value="{{ $option['label_en'] }}">
          <label for="opt-{{ $i }}-ar">Arabic</label>
          <input id="opt-{{ $i }}-ar" name="options[{{ $i }}][label_ar]" type="text" maxlength="100" dir="rtl" lang="ar" value="{{ $option['label_ar'] }}">
          <label class="check"><input type="checkbox" name="options[{{ $i }}][is_active]" value="1" @checked($option['is_active'] ?? true)> Active</label>
        </fieldset>
      @endforeach
      @for ($i = 0; $i < 3; $i++)
        <fieldset class="option-row">
          <legend>New choice</legend>
          <label for="new-{{ $i }}-value">Value <span class="hint">(a-z, 0-9, _ or -)</span></label>
          <input id="new-{{ $i }}-value" name="new_options[{{ $i }}][value]" type="text" maxlength="40" dir="ltr">
          <label for="new-{{ $i }}-en">English</label>
          <input id="new-{{ $i }}-en" name="new_options[{{ $i }}][label_en]" type="text" maxlength="100">
          <label for="new-{{ $i }}-ar">Arabic</label>
          <input id="new-{{ $i }}-ar" name="new_options[{{ $i }}][label_ar]" type="text" maxlength="100" dir="rtl" lang="ar">
        </fieldset>
      @endfor
    @endif

    <div class="actions">
      <button type="submit" class="btn">Save</button>
      <a href="{{ route('fields.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
