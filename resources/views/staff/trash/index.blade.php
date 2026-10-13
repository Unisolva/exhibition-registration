{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Trash')

@section('content')
<h1>Trash</h1>
<p>Deleted visitors stay here for {{ \App\Http\Controllers\Staff\TrashController::DAYS }} days and can be restored. After that they are deleted for good.</p>

@if ($visitors->isEmpty())
  <p>The trash is empty.</p>
@else
  <table class="stack-sm">
    <thead><tr><th>Name</th><th>Exhibition</th><th>Deleted</th><th>Days left</th><th><span class="hint">Actions</span></th></tr></thead>
    <tbody>
    @foreach ($visitors as $visitor)
      @php($left = max(0, \App\Http\Controllers\Staff\TrashController::DAYS - (int) $visitor->trashed_at->diffInDays(now())))
      <tr>
        <td>{{ $visitor->name }}</td>
        <td>{{ $visitor->exhibition?->name }}</td>
        <td>{{ $visitor->trashed_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $visitor->trashedBy?->name ?? '?' }}</td>
        <td>{{ $left }}</td>
        <td>
          <form method="post" action="{{ route('trash.restore', $visitor) }}" class="inline">
            @csrf
            <button type="submit" class="btn btn-secondary">Restore</button>
          </form>
          @can('forceDelete', $visitor)
            <form method="post" action="{{ route('trash.destroy', $visitor) }}" class="inline purge">
              @csrf
              @method('DELETE')
              <label for="confirm-{{ $visitor->id }}">Type {{ \App\Http\Controllers\Staff\TrashController::CONFIRM_WORD }} to delete permanently</label>
              <input id="confirm-{{ $visitor->id }}" name="confirm" type="text" autocomplete="off" maxlength="10">
              @error('confirm', 'delete'.$visitor->id)<div class="error">{{ $message }}</div>@enderror
              <button type="submit" class="btn btn-secondary">Delete permanently</button>
            </form>
          @endcan
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <nav class="actions pager" aria-label="Pages">
    @if ($visitors->previousPageUrl())<a href="{{ $visitors->previousPageUrl() }}">← Previous</a>@endif
    @if ($visitors->nextPageUrl())<a href="{{ $visitors->nextPageUrl() }}">Next →</a>@endif
  </nav>
@endif
@endsection
