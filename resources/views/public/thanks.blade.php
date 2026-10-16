{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.public')

@section('title', __('public.thanks_title'))

@section('content')
<div class="card" role="status">
  <h1>{{ __('public.thanks_title') }}</h1>
  <p>{{ __('public.thanks_body', ['exhibition' => $exhibition->name]) }}</p>
  <p><a href="{{ route('public.show', ['token' => $exhibition->public_token]) }}">{{ __('public.thanks_again') }}</a></p>
</div>
@endsection
