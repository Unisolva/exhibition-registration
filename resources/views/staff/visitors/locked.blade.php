{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Locked')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">This visitor is locked</h1></div>
  <div class="alert alert-warning notice notice-warn" role="alert"><x-lock-icon /> {{ $message }}.</div>
  <p><a href="{{ route('visitors.index') }}">Back to the list</a></p>
</div>
@endsection
