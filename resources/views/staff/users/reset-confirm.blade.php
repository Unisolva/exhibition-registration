{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Reset password')

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Reset {{ $user->name }}'s password?</h1></div>
  <form method="post" action="{{ route('users.reset-password', $user) }}" class="card card-body" data-once>
    @csrf
    <p>They are signed out on every device and need the new one-time password.</p>
    <div class="actions">
      <button type="submit" class="btn btn-danger">Reset password</button>
      <a href="{{ route('users.edit', $user) }}">Cancel</a>
    </div>
  </form>
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
