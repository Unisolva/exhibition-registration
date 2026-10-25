{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Close registration')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Close registration for {{ $exhibition->name }}?</h1></div>
  <form method="post" action="{{ route('exhibitions.close', $exhibition) }}" class="card card-body" data-once>
    @csrf
    <p>Visitors who scan the QR will see "Registration is closed", and staff can no longer enter visitors for it in Save &amp; New.</p>
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
