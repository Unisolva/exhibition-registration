{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.public')

@section('title', __('public.closed_title'))

@section('content')
<div class="card">
  <h1>{{ __('public.closed_title') }}</h1>
  <p>{{ __('public.closed_body') }}</p>
</div>
@endsection
