{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($editing = $user->exists)

@section('title', $editing ? 'Edit user' : 'Add user')

@section('content')
<div class="narrow">
  <h1>{{ $editing ? 'Edit '.$user->name : 'Add user' }}</h1>
  <form method="post" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="card" novalidate>
    @csrf
    @if ($editing) @method('PUT') @endif

    <x-field name="name" label="Name" :value="old('name', $user->name)" required maxlength="100" />
    <x-field type="email" name="email" label="Email" :value="old('email', $user->email)" required maxlength="254" />

    @if ($editing && auth()->user()->is($user) && ! auth()->user()->isSuperAdmin())
      <div class="field">
        <input type="hidden" name="role" value="{{ $user->role->value }}">
        <p><strong>Role:</strong> {{ $user->role->label() }} <span class="hint">(you cannot change your own role)</span></p>
      </div>
    @else
      <x-field type="select" name="role" label="Role" :value="old('role', $user->role?->value ?? 'staff')" required
               :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->label()])->all()" />
    @endif

    @if ($editing)
      <input type="hidden" name="is_active" value="0">
      <x-field type="checkbox" name="is_active" label="Active" hint="Deactivating signs the user out everywhere."
               :checked="(bool) old('is_active', $user->is_active)" :disabled="auth()->user()->is($user)" />
      @if (auth()->user()->is($user))<input type="hidden" name="is_active" value="1">@endif
    @else
      <p class="hint">A one-time password is generated and shown once after saving. The user must change it at first sign-in.</p>
    @endif

    <div class="actions">
      <button type="submit" class="btn">Save</button>
      <a href="{{ route('users.index') }}">Cancel</a>
    </div>
  </form>

  @if ($editing)
    @can('resetPassword', $user)
      <h2>Reset password</h2>
      <div class="card">
        <p>Generates a new one-time password and signs {{ $user->name }} out everywhere.</p>
        <a class="btn btn-secondary" href="{{ route('users.reset-password-confirm', $user) }}">Reset password…</a>
      </div>
    @endcan
  @endif
</div>
@endsection
