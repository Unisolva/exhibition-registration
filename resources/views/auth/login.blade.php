{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Sign in')

@section('content')
<div class="narrow">
  {{-- Whose system this is (M8 L5). --}}
  <p class="org-line">{{ app(\App\Support\Branding::class)->orgName('en') }}</p>
  <h1>Sign in</h1>
  <form method="post" action="{{ url('/staff/login') }}" class="card" novalidate>
    @csrf
    <x-field type="email" name="email" label="Email" :value="old('email')" autocomplete="username" required maxlength="254" autofocus />
    <x-field type="password" name="password" label="Password" autocomplete="current-password" required maxlength="128" />
    <x-field type="checkbox" name="remember" label="Keep me signed in on this device" />
    <div class="actions"><button type="submit" class="btn">Sign in</button></div>
  </form>
</div>
@endsection
