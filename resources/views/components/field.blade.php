{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{--
  One form field (M8 CO-9): label, optional hint, the control and its error, with aria-invalid and
  aria-describedby wired to the hint and the error. Any other attribute (autocomplete, maxlength,
  dir, min, accept, autofocus...) goes to the control.

  type: text, email, tel, password, date, number, url, file, select, radio, textarea or checkbox.
  The error comes from the error bag ($bag, key $errorKey or $name), or from $error when the
  caller has its own messages (the registration forms).
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'id' => null,
    'value' => null,
    'options' => [],
    'placeholder' => null,
    'checked' => false,
    'hint' => null,
    'error' => null,
    'errorKey' => null,
    'bag' => 'default',
    'required' => false,
    'mark' => false,
])
@php
    $id ??= trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $name), '-');
    $message = $error ?? $errors->getBag($bag)->first($errorKey ?? $name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
    $control = $attributes->merge(array_filter([
        'id' => $id,
        'name' => $name,
        'required' => $required,
        'aria-required' => $required ? 'true' : null,
        'aria-invalid' => $message ? 'true' : null,
        'aria-describedby' => $describedBy ?: null,
    ], fn ($v) => $v !== null && $v !== false));
@endphp
@if ($type === 'radio')
  {{-- M9 CO-3: a fieldset whose legend is the label; the error is tied to the fieldset. An optional
       group starts with "Not set" ($placeholder, empty value); a required one has nothing preselected. --}}
  @php($choices = $placeholder !== null ? ['' => $placeholder] + $options : $options)
  <fieldset class="field field-radio" id="{{ $id }}" @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif>
    <legend>{{ $label }}@if ($mark)<span class="req" aria-hidden="true"> *</span>@endif</legend>
    @if ($hint)<div class="hint" id="{{ $id }}-hint">{{ $hint }}</div>@endif
    @foreach ($choices as $optionValue => $optionLabel)
      <label class="radio"><input type="radio" name="{{ $name }}" value="{{ $optionValue }}" id="{{ $id }}-{{ $loop->index }}"
        @checked((string) $value === (string) $optionValue) @if ($required) required @endif @if ($message) aria-invalid="true" @endif
        @if ($loop->first && $attributes->get('autofocus')) autofocus @endif> {{ $optionLabel }}</label>
    @endforeach
    @if ($message)<div class="error" id="{{ $id }}-error">{{ $message }}</div>@endif
  </fieldset>
@elseif ($type === 'checkbox')
  <div class="field field-check">
    <label class="check"><input type="checkbox" value="{{ $value ?? '1' }}" @checked($checked) {{ $control }}> {{ $label }}</label>
    @if ($hint)<div class="hint" id="{{ $id }}-hint">{{ $hint }}</div>@endif
    @if ($message)<div class="error" id="{{ $id }}-error">{{ $message }}</div>@endif
  </div>
@else
  <div class="field">
    <label for="{{ $id }}">{{ $label }}@if ($mark)<span class="req" aria-hidden="true"> *</span>@endif</label>
    @if ($hint)<div class="hint" id="{{ $id }}-hint">{{ $hint }}</div>@endif
    @if ($type === 'select')
      <select {{ $control }}>
        @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
        @foreach ($options as $optionValue => $optionLabel)
          <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
      </select>
    @elseif ($type === 'textarea')
      <textarea {{ $control }}>{{ $value }}</textarea>
    @elseif (in_array($type, ['password', 'file'], true))
      <input type="{{ $type }}" {{ $control }}>
    @else
      <input type="{{ $type }}" value="{{ $value }}" {{ $control }}>
    @endif
    @if ($message)<div class="error" id="{{ $id }}-error">{{ $message }}</div>@endif
    {{ $slot }}
  </div>
@endif
