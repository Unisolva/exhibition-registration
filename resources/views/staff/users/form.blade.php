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

    <div class="field">
      <label for="name">Name</label>
      <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $user->name) }}"
             @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
      @error('name')<div class="error" id="name-error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required maxlength="254" value="{{ old('email', $user->email) }}"
             @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
      @error('email')<div class="error" id="email-error">{{ $message }}</div>@enderror
    </div>

    <div class="field">
      <label for="role">Role</label>
      @if ($editing && auth()->user()->is($user) && ! auth()->user()->isSuperAdmin())
        <input type="hidden" name="role" value="{{ $user->role->value }}">
        <p>{{ $user->role->label() }} <span class="hint">(you cannot change your own role)</span></p>
      @else
        <select id="role" name="role" required @error('role') aria-invalid="true" aria-describedby="role-error" @enderror>
          @foreach ($roles as $role)
            <option value="{{ $role->value }}" @selected(old('role', $user->role?->value ?? 'staff') === $role->value)>{{ $role->label() }}</option>
          @endforeach
        </select>
      @endif
      @error('role')<div class="error" id="role-error">{{ $message }}</div>@enderror
    </div>

    @if ($editing)
      <input type="hidden" name="is_active" value="0">
      <label class="check">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) @disabled(auth()->user()->is($user))>
        Active <span class="hint">(deactivating signs the user out everywhere)</span>
      </label>
      @if (auth()->user()->is($user))<input type="hidden" name="is_active" value="1">@endif
      @error('is_active')<div class="error">{{ $message }}</div>@enderror
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
      <form method="post" action="{{ route('users.reset-password', $user) }}" class="card">
        @csrf
        <p>Generates a new one-time password and signs {{ $user->name }} out everywhere.</p>
        <button type="submit" class="btn btn-secondary">Reset password</button>
      </form>
    @endcan
  @endif
</div>
@endsection
