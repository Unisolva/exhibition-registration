{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- After a failed submit of a registration form (M8 CO-10): "Please fix {n} fields:" and a link to each.
     Expects $fields (FormView::fields) and $fieldErrors; a form-level message (_form) is shown on its own. --}}
@php($invalid = collect($fields)->filter(fn ($field) => isset($fieldErrors[$field['key']]))->values())
@if (isset($fieldErrors['_form']))
  <div class="notice notice-err" role="alert">{{ $fieldErrors['_form'] }}</div>
@elseif ($invalid->isNotEmpty())
  <div class="notice notice-err error-summary" role="alert">
    <p>{{ trans_choice('public.errors_summary', $invalid->count(), ['count' => $invalid->count()]) }}</p>
    <ul>
      @foreach ($invalid as $field)
        <li><a href="#f-{{ $field['key'] }}">{{ $field['label'] }}: {{ $fieldErrors[$field['key']] }}</a></li>
      @endforeach
    </ul>
  </div>
@endif
