{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'One-time password')

@section('content')
<div class="narrow">
  <h1>{{ $created ? 'User created' : 'Password reset' }}</h1>
  <div class="card">
    <p>One-time password for <strong>{{ $user->name }}</strong> ({{ $user->email }}):</p>
    <p class="secret">{{ $password }}</p>
    <div class="notice notice-warn" role="status">This password is shown only now. Give it to the user in person; they must change it when they first sign in.</div>
    <p><a class="btn" href="{{ route('users.index') }}">Done</a></p>
  </div>
</div>
@endsection
