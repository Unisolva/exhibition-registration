{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Save & New')

@section('content')
<div class="entry-bar" role="region" aria-label="Exhibition">
  <strong>{{ $exhibition->name }}</strong>
  @unless ($exhibition->is_open)<span class="badge badge-off">Closed to the public</span>@endunless
  <a href="{{ route('staff.entry', ['change' => 1]) }}">Change exhibition</a>
</div>

<div class="narrow">
  <h1>Save &amp; New</h1>

  <div id="entry-status" class="entry-status" role="status" aria-live="polite">
    @if ($notice)<div class="notice notice-warn">{{ $notice }}</div>@endif
    @if (isset($fieldErrors['_form']))<div class="notice notice-err">{{ $fieldErrors['_form'] }}</div>@endif
  </div>

  <form method="post" action="{{ route('staff.registrations.store') }}" class="card" novalidate data-entry
        data-session-url="{{ route('staff.session') }}">
    @csrf
    <input type="hidden" name="exhibition_id" value="{{ $exhibition->id }}">
    <input type="hidden" name="submission_token" value="{{ $submissionToken }}">

    <div class="entry-fields">
      @include('partials.registration-fields')
    </div>

    <div class="actions">
      <button type="submit" class="btn" data-save>Save &amp; New</button>
      <button type="button" class="btn btn-secondary" data-retry hidden>Retry</button>
      <button type="button" class="btn btn-secondary" data-save-new hidden>Save as a new entry</button>
    </div>
  </form>
  <p class="hint">Typed values stay on this page until they are saved. Nothing is kept in the browser.</p>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('entry.js') }}" defer></script>
@endpush
