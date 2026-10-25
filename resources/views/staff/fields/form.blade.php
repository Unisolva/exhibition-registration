{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($editing = $field->exists)
@php($alwaysOn = in_array($field->key, ['name', 'phone'], true))

@section('title', $editing ? 'Edit field' : 'Add field')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">{{ $editing ? 'Edit field: '.$field->label_en : 'Add a field' }}</h1></div>
  <form method="post" action="{{ $editing ? route('fields.update', $field) : route('fields.store') }}" class="card card-body" novalidate>
    @csrf
    @if ($editing) @method('PUT') @endif

    @if ($editing)
      <p>Key <code>{{ $field->key }}</code> · type {{ $field->type }}@if ($field->is_system) · system field @endif <span class="hint">(key and type never change)</span></p>
    @else
      <x-field name="key" label="Key" hint="Lower-case letters, digits and _, starting with a letter. Cannot be changed later."
               :value="old('key')" required maxlength="40" dir="ltr" />
      <x-field type="select" name="type" label="Type" :value="old('type', 'text')" :options="['text' => 'Short text', 'select' => 'List of choices']" />
    @endif

    <x-field name="label_en" label="Label in English" :value="old('label_en', $field->label_en)" required maxlength="100" />
    <x-field name="label_ar" label="Label in Arabic" :value="old('label_ar', $field->label_ar)" maxlength="100" dir="rtl" lang="ar"
             :hint="$field->is_system ? null : 'Needed when the field is shown on the public form; optional for staff-only fields.'" />
    <x-field type="number" name="sort_order" label="Order" hint="Lower comes first." :value="old('sort_order', $field->sort_order)" required min="0" max="100000" />

    @if ($alwaysOn)
      <p class="hint">Name and phone are always active and required.</p>
    @else
      <x-field type="checkbox" name="is_required" label="Required" :checked="(bool) old('is_required', $field->is_required)" />
      <x-field type="checkbox" name="is_active" label="Active (shown on the forms)" :checked="(bool) old('is_active', $field->is_active)" />
    @endif
    <x-field type="checkbox" name="send_to_erp" label="Send to the ERP with the lead" :checked="(bool) old('send_to_erp', $field->send_to_erp)" />

    {{-- M9 CO-1: system fields are always shown to everyone, so the choice is not offered for them. --}}
    @unless ($field->is_system)
      <x-field type="radio" name="audience" label="Shown on" :value="old('audience', $field->audience ?? 'all')"
               :options="['all' => 'Public form and staff entry', 'staff' => 'Staff entry only']" />
    @endunless

    @if (! $editing || $field->type === 'select')
      <section class="choices" aria-labelledby="choices-title" data-choices>
        <h2 id="choices-title">Choices</h2>
        <p class="hint" id="choices-hint">For a list of choices. A list with no active choices is shown as a free-text box. Choice values cannot change once added; deactivate a choice to hide it.</p>
        @error('new_options')<p class="alert alert-danger notice notice-err" role="alert">{{ $message }}</p>@enderror
        <x-field type="radio" name="widget" label="Show as" :value="old('widget', $field->widget ?? 'select')"
                 :options="['select' => 'Dropdown', 'radio' => 'Radio buttons']" />
        @foreach ($field->options ?? [] as $i => $option)
          <fieldset class="option-row">
            <legend><code>{{ $option['value'] }}</code></legend>
            <x-field :name="'options['.$i.'][label_en]'" :id="'opt-'.$i.'-en'" :error-key="'options.'.$i.'.label_en'" label="English"
                     :value="old('options.'.$i.'.label_en', $option['label_en'])" maxlength="100" />
            <x-field :name="'options['.$i.'][label_ar]'" :id="'opt-'.$i.'-ar'" :error-key="'options.'.$i.'.label_ar'" label="Arabic"
                     :value="old('options.'.$i.'.label_ar', $option['label_ar'])" maxlength="100" dir="rtl" lang="ar" />
            <x-field type="checkbox" :name="'options['.$i.'][is_active]'" :id="'opt-'.$i.'-active'" label="Active"
                     :checked="(bool) old('options.'.$i.'.is_active', $option['is_active'] ?? true)" />
          </fieldset>
        @endforeach
        @for ($i = 0; $i < 3; $i++)
          <fieldset class="option-row">
            <legend>New choice</legend>
            <x-field :name="'new_options['.$i.'][value]'" :id="'new-'.$i.'-value'" :error-key="'new_options.'.$i.'.value'" label="Value"
                     hint="a-z, 0-9, _ or -" :value="old('new_options.'.$i.'.value')" maxlength="40" dir="ltr" />
            <x-field :name="'new_options['.$i.'][label_en]'" :id="'new-'.$i.'-en'" :error-key="'new_options.'.$i.'.label_en'" label="English"
                     :value="old('new_options.'.$i.'.label_en')" maxlength="100" />
            <x-field :name="'new_options['.$i.'][label_ar]'" :id="'new-'.$i.'-ar'" :error-key="'new_options.'.$i.'.label_ar'" label="Arabic"
                     :value="old('new_options.'.$i.'.label_ar')" maxlength="100" dir="rtl" lang="ar" />
          </fieldset>
        @endfor
      </section>
    @endif

    <div class="actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="{{ route('fields.index') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
