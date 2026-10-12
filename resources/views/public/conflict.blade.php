{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.public')

@section('title', __('public.conflict_title'))

@section('content')
<div class="card">
  <h1>{{ __('public.conflict_title') }}</h1>
  <p>{{ __('public.conflict_body') }}</p>
  <p><a href="{{ route('public.show', ['token' => $exhibition->public_token]) }}">{{ __('public.conflict_again') }}</a></p>
</div>
@endsection
