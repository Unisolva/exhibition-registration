{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Package')

@section('content')
@include('staff.packages.bar')
<div class="narrow">
  <h1>Package</h1>
  @if ($visitors->isEmpty())
    {{-- The same words whether the code or number is unknown, trashed, deleted, or at an exhibition without a package. --}}
    <div class="notice notice-warn" role="status">{{ $searched === 'code' ? 'No package found for this code.' : 'No package found for this number.' }}</div>
  @else
    @if ($visitors->count() > 1)
      <p>{{ $visitors->count() }} registrations have this number at {{ $exhibition->name }}.</p>
    @endif
    @foreach ($visitors as $visitor)
      @include('staff.packages.card', ['visitor' => $visitor])
    @endforeach
  @endif
  <p><a class="btn btn-secondary" href="{{ route('packages.index') }}">Find another package</a></p>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
