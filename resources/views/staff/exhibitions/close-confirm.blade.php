{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Close registration')

@section('content')
<div class="narrow">
  <h1>Close registration for {{ $exhibition->name }}?</h1>
  <form method="post" action="{{ route('exhibitions.close', $exhibition) }}" class="card" data-once>
    @csrf
    <p>Visitors who scan the QR will see "Registration is closed". Staff can still enter visitors.</p>
    <div class="actions">
      <button type="submit" class="btn btn-danger">Close registration</button>
      <a href="{{ route('exhibitions.show', $exhibition) }}">Cancel</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
