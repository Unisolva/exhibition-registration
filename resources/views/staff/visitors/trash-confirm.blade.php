{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Delete '.$visitor->name)

@section('content')
<div class="narrow">
  <div class="page-header"><h1 class="page-title">Delete {{ $visitor->name }}?</h1></div>
  @if ($lock)
    <div class="alert alert-warning notice notice-warn" role="status"><x-lock-icon /> {{ \App\Visitors\VisitorLock::message($lock) }}. It cannot be deleted.</div>
  @else
    <form method="post" action="{{ route('visitors.destroy', $visitor) }}" class="card card-body">
      @csrf
      @method('DELETE')
      <p>The visitor moves to the trash. An admin can restore it for 30 days; after that it is deleted for good.</p>
      <div class="actions">
        <button type="submit" class="btn btn-danger">Move to trash</button>
        <a href="{{ route('visitors.show', $visitor) }}">Cancel</a>
      </div>
    </form>
  @endif
</div>
@endsection
