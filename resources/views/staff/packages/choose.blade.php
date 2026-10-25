{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Packages: choose exhibition')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Packages</h1></div>
  @if ($exhibitions->isEmpty())
    <p>There are no exhibitions yet. An admin creates them under Exhibitions.</p>
  @else
    <form method="get" action="{{ route('packages.index') }}" class="card card-body">
      <x-field type="select" name="exhibition" label="Which exhibition are you handing packages out at?" required
               :options="$exhibitions->mapWithKeys(fn ($e) => [$e->id => $e->pickerLabel().($e->offers_package ? '' : ' · no package')])->all()" />
      <p class="hint">The same choice is used by Save &amp; New.</p>
      <div class="actions"><button type="submit" class="btn btn-primary">Start</button></div>
    </form>
  @endif
</div>
@endsection
