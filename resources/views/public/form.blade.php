{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.public')

@section('title', __('public.register_title'))

@section('content')
<h1>{{ __('public.register_title') }}</h1>
<p>{{ __('public.intro') }} <span class="hint">{{ __('public.required_note') }}</span></p>

@if ($notice)
  <div class="notice notice-warn" role="alert">{{ $notice }}</div>
@endif
@include('partials.error-summary')

<form method="post" action="{{ route('public.submit', ['token' => $exhibition->public_token]) }}" class="card" novalidate data-once>
  @csrf
  <input type="hidden" name="submission_token" value="{{ $submissionToken }}">

  @include('partials.registration-fields')

  <div class="actions"><button type="submit" class="btn btn-block">{{ __('public.submit') }}</button></div>
</form>
@endsection
