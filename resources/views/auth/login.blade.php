{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Sign in')

@section('content')
<div class="narrow">
  <h1>Sign in</h1>
  <form method="post" action="{{ url('/staff/login') }}" class="card" novalidate>
    @csrf
    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="username" required maxlength="254"
             value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror autofocus>
      @error('email')<div class="error" id="email-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128">
    </div>
    <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
    <div class="actions"><button type="submit" class="btn">Sign in</button></div>
  </form>
</div>
@endsection
