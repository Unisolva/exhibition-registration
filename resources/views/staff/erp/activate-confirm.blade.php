{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Change the active connection')

@section('content')
<div class="narrow">
  <h1>Change the active connection</h1>

  @if ($connection->is_active)
    <div class="notice notice-ok" role="status">{{ $connection->label() }} is already active. Nothing changes.</div>
    <p><a class="btn btn-secondary" href="{{ route('erp.show') }}">Back to the ERP screen</a></p>
  @else
    <p>Leads will be sent to:</p>
    <div class="destination destination-{{ $connection->kind }}">
      <div class="destination-kind">{{ $connection->label() }}</div>
      <div dir="ltr">{{ $connection->base_url }} / {{ $connection->database }}</div>
    </div>
    <p class="hint">Allowed only after a passing test, and only when the active connection has no unfinished items.</p>
    <form method="post" action="{{ route('erp.activate') }}" data-once>
      @csrf
      <input type="hidden" name="kind" value="{{ $connection->kind }}">
      <div class="actions">
        <button type="submit" class="btn">Make {{ $connection->label() }} active</button>
        <a href="{{ route('erp.show') }}">Cancel</a>
      </div>
    </form>
  @endif
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
