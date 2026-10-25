{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Trash')

@section('content')
<div class="page-header"><h1 class="page-title">Trash</h1></div>
<p>Deleted visitors stay here for {{ \App\Http\Controllers\Staff\TrashController::DAYS }} days and can be restored. After that they are deleted for good.</p>

@if ($visitors->isEmpty())
  <p>The trash is empty.</p>
@else
  <div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
    <thead><tr><th>Name</th><th>Exhibition</th><th>Deleted</th><th>Days left</th><th><span class="hint">Actions</span></th></tr></thead>
    <tbody>
    @foreach ($visitors as $visitor)
      @php($left = max(0, \App\Http\Controllers\Staff\TrashController::DAYS - (int) $visitor->trashed_at->diffInDays(now())))
      <tr>
        <td data-label="Name">{{ $visitor->name }}</td>
        <td data-label="Exhibition">{{ $visitor->exhibition?->name }}</td>
        <td data-label="Deleted">{{ $visitor->trashed_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $visitor->trashedBy?->name ?? '?' }}</td>
        <td data-label="Days left">{{ $left }}</td>
        <td>
          <form method="post" action="{{ route('trash.restore', $visitor) }}" class="inline">
            @csrf
            <button type="submit" class="btn btn-outline-primary">Restore</button>
          </form>
          @can('forceDelete', $visitor)
            <form method="post" action="{{ route('trash.destroy', $visitor) }}" class="inline purge">
              @csrf
              @method('DELETE')
              <x-field name="confirm" :id="'confirm-'.$visitor->id" :bag="'delete'.$visitor->id" autocomplete="off" maxlength="10"
                       :label="'Type '.\App\Http\Controllers\Staff\TrashController::CONFIRM_WORD.' to delete permanently'" />
              <button type="submit" class="btn btn-danger">Delete permanently</button>
            </form>
          @endcan
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div></div>
  <nav class="actions pager" aria-label="Pages">
    @if ($visitors->previousPageUrl())<a href="{{ $visitors->previousPageUrl() }}">← Previous</a>@endif
    @if ($visitors->nextPageUrl())<a href="{{ $visitors->nextPageUrl() }}">Next →</a>@endif
  </nav>
@endif
@endsection
