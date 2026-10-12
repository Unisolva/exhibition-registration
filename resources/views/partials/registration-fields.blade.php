{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Fields of a registration form. Expects $fields (FormView::fields), $old and $fieldErrors. --}}
@foreach ($fields as $field)
  @php($id = 'f-'.$field['key'])
  @php($error = $fieldErrors[$field['key']] ?? null)
  <div class="field">
    <label for="{{ $id }}">{{ $field['label'] }}@if ($field['required'])<span class="req" aria-hidden="true"> *</span>@endif</label>
    @if ($field['control'] === 'select')
      <select id="{{ $id }}" name="{{ $field['key'] }}"
              @if ($field['required']) required aria-required="true" @endif
              @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <option value="">{{ __('public.choose') }}</option>
        @foreach ($field['options'] as $value => $label)
          <option value="{{ $value }}" @selected(($old[$field['key']] ?? '') === (string) $value)>{{ $label }}</option>
        @endforeach
      </select>
    @else
      <input id="{{ $id }}" name="{{ $field['key'] }}" type="{{ $field['type'] }}" value="{{ $old[$field['key']] ?? '' }}"
             maxlength="{{ $field['maxlength'] }}"
             @if ($field['autocomplete']) autocomplete="{{ $field['autocomplete'] }}" @endif
             @if ($field['inputmode']) inputmode="{{ $field['inputmode'] }}" @endif
             @if ($field['dir']) dir="{{ $field['dir'] }}" @endif
             @if ($field['required']) required aria-required="true" @endif
             @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    @endif
    @if ($error)<div class="error" id="{{ $id }}-error">{{ $error }}</div>@endif
  </div>
@endforeach
