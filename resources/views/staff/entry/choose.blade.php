{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Choose exhibition')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Save &amp; New</h1></div>
  @if ($exhibitions->isEmpty())
    <p>There are no exhibitions yet. An admin creates them under Exhibitions.</p>
  @else
    <form method="get" action="{{ route('staff.entry') }}" class="card card-body">
      <div class="field">
        <label for="exhibition" class="form-label">Which exhibition are you entering visitors for?</label>
        <select class="form-select" id="exhibition" name="exhibition" required>
          @foreach ($exhibitions as $exhibition)
            <option value="{{ $exhibition->id }}">{{ $exhibition->pickerLabel() }}{{ $exhibition->is_open ? '' : ' · closed' }}</option>
          @endforeach
        </select>
      </div>
      <p class="hint">Closed exhibitions are listed too, for typing in paper forms after a show.</p>
      <div class="actions"><button type="submit" class="btn btn-primary">Start</button></div>
    </form>
  @endif
</div>
@endsection
