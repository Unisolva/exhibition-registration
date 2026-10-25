{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Fields of a registration form. Expects $fields (FormView::fields), $old and $fieldErrors.
     After a failed submit the first invalid field gets autofocus (M8 CO-10).
     M10.1 CO-1: a list's default choice is selected on a new entry ($old empty) and, on the edit form
     ($defaultsForEmpty), when nothing is stored; after a submit the visitor's own choice wins. --}}
@php($useDefaults = $old === [] || ($defaultsForEmpty ?? false))
@php($listValue = fn (array $field) => ($old[$field['key']] ?? '') === '' && $useDefaults && ($field['default'] ?? null) !== null ? $field['default'] : ($old[$field['key']] ?? ''))
@php($firstInvalid = collect($fields)->flatMap(fn ($field) => [$field['key'], $field['key'].'_details'])->first(fn ($key) => isset($fieldErrors[$key])))
@foreach ($fields as $field)
  @if ($field['control'] === 'radio')
    <x-field type="radio" :name="$field['key']" :id="'f-'.$field['key']" :label="$field['label']" :default="$field['default'] ?? null"
             :value="$listValue($field)" :options="$field['options']" :placeholder="$field['required'] ? null : __('public.not_set')"
             :error="$fieldErrors[$field['key']] ?? null" :required="$field['required']" :mark="$field['required']"
             :autofocus="$firstInvalid === $field['key']" />
  @elseif ($field['control'] === 'select')
    <x-field type="select" :name="$field['key']" :id="'f-'.$field['key']" :label="$field['label']" :default="$field['default'] ?? null"
             :value="$listValue($field)" :options="$field['options']" :placeholder="__('public.choose')"
             :error="$fieldErrors[$field['key']] ?? null" :required="$field['required']" :mark="$field['required']"
             :autofocus="$firstInvalid === $field['key']" />
  @else
    <x-field :type="$field['type']" :name="$field['key']" :id="'f-'.$field['key']" :label="$field['label']"
             :value="$old[$field['key']] ?? ''" :maxlength="$field['maxlength']"
             :autocomplete="$field['autocomplete'] ?: false" :inputmode="$field['inputmode'] ?: false" :dir="$field['dir'] ?: false"
             :error="$fieldErrors[$field['key']] ?? null" :required="$field['required']" :mark="$field['required']"
             :autofocus="$firstInvalid === $field['key']" />
  @endif
  @if ($field['details'] ?? null)
    {{-- M10 CO-5: shown while the choice that asks for details is selected; without JavaScript always shown. --}}
    @php($detailsKey = $field['key'].'_details')
    <div class="field-details" data-details-for="{{ $field['key'] }}" data-details-value="{{ $field['details'] }}">
      <x-field :name="$detailsKey" :id="'f-'.$detailsKey" :label="__('public.details_label')"
               :hint="__('public.details_hint', ['choice' => $field['options'][$field['details']] ?? $field['details']])"
               :value="$old[$detailsKey] ?? ''" maxlength="100" :error="$fieldErrors[$detailsKey] ?? null"
               :autofocus="$firstInvalid === $detailsKey" />
    </div>
  @endif
@endforeach
