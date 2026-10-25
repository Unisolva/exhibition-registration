{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Users')

@section('content')
<x-page-header title="Users">
  <a class="btn btn-primary" href="{{ route('users.create') }}"><x-icon name="plus" /> Add user</a>
</x-page-header>
<div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
  <thead>
    <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last sign-in</th><th><span class="hint">Actions</span></th></tr>
  </thead>
  <tbody>
  @foreach ($users as $user)
    <tr>
      <td data-label="Name">{{ $user->name }}</td>
      <td data-label="Email">{{ $user->email }}</td>
      <td data-label="Role">{{ $user->role->label() }}</td>
      <td data-label="Status">
        @if ($user->is_active)<span class="badge bg-success-lt">Active</span>@else<span class="badge bg-secondary-lt">Deactivated</span>@endif
        @if ($user->must_change_password)<span class="badge bg-warning-lt">One-time password</span>@endif
      </td>
      <td data-label="Last sign-in">{{ $user->last_login_at?->timezone(app(\App\Support\Settings::class)->get('display_timezone'))->format('Y-m-d H:i') ?? 'Never' }}</td>
      <td>
        @can('update', $user)<a href="{{ route('users.edit', $user) }}">Edit</a>@endcan
      </td>
    </tr>
  @endforeach
  </tbody>
</table></div></div>
@endsection
