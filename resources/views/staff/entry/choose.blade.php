{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Save & New')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Save &amp; New</h1></div>
  @if ($closed)
    <div class="alert alert-warning notice notice-warn" role="status">Registration for {{ $closed->name }} is closed.</div>
  @endif
  @if ($exhibitions->isEmpty())
    {{-- M10 CO-7: only open exhibitions take entries. --}}
    <p>No exhibition is open for registration. An admin opens one under Exhibitions.</p>
  @else
    <form method="get" action="{{ route('staff.entry') }}" class="card card-body">
      <div class="field">
        <label for="exhibition" class="form-label">Which exhibition are you entering visitors for?</label>
        <select class="form-select" id="exhibition" name="exhibition" required>
          @foreach ($exhibitions as $exhibition)
            <option value="{{ $exhibition->id }}">{{ $exhibition->pickerLabel() }}</option>
          @endforeach
        </select>
      </div>
      <div class="actions"><button type="submit" class="btn btn-primary">Start</button></div>
    </form>
  @endif
</div>
@endsection
