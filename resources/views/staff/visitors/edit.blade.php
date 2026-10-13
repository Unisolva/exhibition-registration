{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Edit '.$visitor->name)

@section('content')
<div class="narrow">
  <h1>Edit {{ $visitor->name }}</h1>
  @if ($lock)
    <div class="notice notice-warn" role="status">🔒 {{ \App\Visitors\VisitorLock::message($lock) }}. Saving will be refused.</div>
  @endif
  @if ($fieldErrors)
    <div class="notice notice-err" role="alert">Please check the fields marked below.</div>
  @endif
  <form method="post" action="{{ route('visitors.update', $visitor) }}" class="card" novalidate data-once>
    @csrf
    @method('PUT')
    @include('partials.registration-fields')
    <p class="hint">Every change is written to the edit log. Editing never changes anything already in the ERP.</p>
    <div class="actions">
      <button type="submit" class="btn">Save</button>
      <a href="{{ route('visitors.show', $visitor) }}">Cancel</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
