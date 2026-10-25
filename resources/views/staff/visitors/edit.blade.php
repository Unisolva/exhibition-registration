{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Edit '.$visitor->name)

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Edit {{ $visitor->name }}</h1></div>
  @if ($lock)
    <div class="alert alert-warning notice notice-warn" role="status"><x-lock-icon /> {{ \App\Visitors\VisitorLock::message($lock) }}. Saving will be refused.</div>
  @endif
  @include('partials.error-summary')
  <form method="post" action="{{ route('visitors.update', $visitor) }}" class="card card-body" novalidate data-once>
    @csrf
    @method('PUT')
    @include('partials.registration-fields')
    <p class="hint">Every change is written to the edit log. Editing never changes anything already in the ERP.</p>
    <div class="actions">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="{{ route('visitors.show', $visitor) }}">Cancel</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
