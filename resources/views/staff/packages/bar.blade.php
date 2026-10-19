{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@if ($exhibition)
  <div class="entry-bar" role="region" aria-label="Exhibition">
    <strong>{{ $exhibition->name }}</strong>
    <a href="{{ route('packages.index', ['change' => 1]) }}">Change exhibition</a>
  </div>
@endif
