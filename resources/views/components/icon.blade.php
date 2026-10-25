{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- A Tabler icon as inline SVG, always next to text, so hidden from screen readers (M10 CO-1). --}}
@props(['name'])
<svg xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => 'icon']) }} width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">@foreach (\App\Support\Icons::PATHS[$name] as $d)<path d="{{ $d }}"/>@endforeach</svg>
