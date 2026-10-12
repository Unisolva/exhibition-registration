{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Change password')

@section('content')
<div class="narrow">
  <h1>Change password</h1>
  @if ($forced)
    <div class="notice notice-warn" role="status">You signed in with a one-time password. Choose your own password to continue.</div>
  @endif
  <form method="post" action="{{ route('password.update') }}" class="card" novalidate>
    @csrf
    @method('PUT')
    <div class="field">
      <label for="current_password">Current password</label>
      <input id="current_password" name="current_password" type="password" autocomplete="current-password" required
             @error('current_password') aria-invalid="true" aria-describedby="current_password-error" @enderror>
      @error('current_password')<div class="error" id="current_password-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password">New password <span class="hint">(at least 10 characters)</span></label>
      <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" maxlength="128"
             @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
      @error('password')<div class="error" id="password-error">{{ $message }}</div>@enderror
    </div>
    <div class="field">
      <label for="password_confirmation">Repeat new password</label>
      <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    </div>
    <p class="hint">Changing your password signs you out on every other device.</p>
    <div class="actions"><button type="submit" class="btn">Change password</button></div>
  </form>
</div>
@endsection
