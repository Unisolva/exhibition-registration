{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- Every staff page starts with this (M10 CO-2): the title, an optional subtitle, the main action on the right. --}}
@props(['title', 'subtitle' => null])
<div class="page-header">
  <div class="row g-2 align-items-center">
    <div class="col">
      <h1 class="page-title">{{ $title }}</h1>
      @if ($subtitle !== null && $subtitle !== '')
        <p class="page-subtitle">{{ $subtitle }}</p>
      @endif
    </div>
    @if (trim((string) $slot) !== '')
      <div class="col-auto ms-auto page-actions">{{ $slot }}</div>
    @endif
  </div>
</div>
