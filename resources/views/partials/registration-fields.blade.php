{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Fields of a registration form. Expects $fields (FormView::fields), $old and $fieldErrors.
     After a failed submit the first invalid field gets autofocus (M8 CO-10). --}}
@php($firstInvalid = collect($fields)->first(fn ($field) => isset($fieldErrors[$field['key']]))['key'] ?? null)
@foreach ($fields as $field)
  @if ($field['control'] === 'select')
    <x-field type="select" :name="$field['key']" :id="'f-'.$field['key']" :label="$field['label']"
             :value="$old[$field['key']] ?? ''" :options="$field['options']" :placeholder="__('public.choose')"
             :error="$fieldErrors[$field['key']] ?? null" :required="$field['required']" :mark="$field['required']"
             :autofocus="$firstInvalid === $field['key']" />
  @else
    <x-field :type="$field['type']" :name="$field['key']" :id="'f-'.$field['key']" :label="$field['label']"
             :value="$old[$field['key']] ?? ''" :maxlength="$field['maxlength']"
             :autocomplete="$field['autocomplete'] ?: false" :inputmode="$field['inputmode'] ?: false" :dir="$field['dir'] ?: false"
             :error="$fieldErrors[$field['key']] ?? null" :required="$field['required']" :mark="$field['required']"
             :autofocus="$firstInvalid === $field['key']" />
  @endif
@endforeach
