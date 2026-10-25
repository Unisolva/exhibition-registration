{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Change password')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Change password</h1></div>
  @if ($forced)
    <div class="alert alert-warning notice notice-warn" role="status">You signed in with a one-time password. Choose your own password to continue.</div>
  @endif
  <form method="post" action="{{ route('password.update') }}" class="card card-body" novalidate>
    @csrf
    @method('PUT')
    <x-field type="password" name="current_password" label="Current password" autocomplete="current-password" required />
    <x-field type="password" name="password" label="New password" hint="At least 10 characters." autocomplete="new-password" required minlength="10" maxlength="128" />
    <x-field type="password" name="password_confirmation" label="Repeat new password" autocomplete="new-password" required />
    <p class="hint">Changing your password signs you out on every other device.</p>
    <div class="actions"><button type="submit" class="btn btn-primary">Change password</button></div>
  </form>
</div>
@endsection
