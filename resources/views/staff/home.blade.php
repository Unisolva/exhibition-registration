{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Home')

@section('content')
<h1>Welcome, {{ auth()->user()->name }}</h1>
<p>You are signed in as <strong>{{ auth()->user()->role->label() }}</strong>.</p>
@can('users.manage')
  <p><a href="{{ route('users.index') }}">Manage users</a></p>
@endcan
@endsection
