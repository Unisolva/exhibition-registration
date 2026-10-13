{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Locked')

@section('content')
<div class="narrow">
  <h1>This visitor is locked</h1>
  <div class="notice notice-warn" role="alert">🔒 {{ $message }}.</div>
  <p><a href="{{ route('visitors.index') }}">Back to the list</a></p>
</div>
@endsection
