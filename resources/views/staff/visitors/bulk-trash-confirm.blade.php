{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Move to trash')

@section('content')
<div class="narrow">
  {{-- M10 CO-8: the names first; locked visitors and those being sent to the ERP are skipped. --}}
  <div class="page-header"><h1 class="page-title">Move {{ $movable->count() }} {{ $movable->count() === 1 ? 'visitor' : 'visitors' }} to the trash?</h1></div>
  @if ($skipped->isNotEmpty())
    <div class="alert alert-warning notice notice-warn" role="status">
      <p class="mb-1">{{ $skipped->count() === 1 ? '1 selected visitor is' : $skipped->count().' selected visitors are' }} skipped:</p>
      <ul class="mb-0">
        @foreach ($skipped as $visitor)
          <li>{{ $visitor->name }}: {{ \App\Visitors\VisitorLock::message($locks[$visitor->id]) }}</li>
        @endforeach
      </ul>
    </div>
  @endif
  @if ($movable->isEmpty())
    <p>None of the selected visitors can be moved to the trash.</p>
    <p><a href="{{ route('visitors.index') }}">Back to the visitors</a></p>
  @else
    <form method="post" action="{{ route('visitors.bulk-trash') }}" class="card card-body" data-once>
      @csrf
      <ul class="bulk-names">
        @foreach ($movable as $visitor)
          <li>{{ $visitor->name }} <span class="text-secondary" dir="ltr">{{ $visitor->phone_raw }}</span><input type="hidden" name="visitor_ids[]" value="{{ $visitor->id }}"></li>
        @endforeach
      </ul>
      <p>They move to the trash. An admin can restore them for 30 days; after that they are deleted for good.</p>
      <div class="actions">
        <button type="submit" class="btn btn-danger">Move {{ $movable->count() === 1 ? 'it' : 'them' }} to trash</button>
        <a href="{{ route('visitors.index') }}">Cancel</a>
      </div>
    </form>
  @endif
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
