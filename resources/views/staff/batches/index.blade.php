{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Batches')

@section('content')
<h1>Batches</h1>
@if ($batches->isEmpty())
  <p>No batches yet. Select visitors on the visitor list to send them to the ERP.</p>
@else
  <table class="stack-sm">
    <thead><tr><th>Batch</th><th>Destination</th><th>Items</th><th>Status</th><th>By</th></tr></thead>
    <tbody>
    @foreach ($batches as $batch)
      <tr>
        <td data-label="Batch"><a href="{{ route('batches.show', $batch) }}">#{{ $batch->id }}</a> · {{ $batch->created_at->format('Y-m-d H:i') }}</td>
        <td data-label="Destination"><span class="badge badge-{{ $batch->connection->kind }}">{{ $batch->connection->label() }}</span></td>
        <td data-label="Items">{{ $batch->item_count }}</td>
        <td data-label="Status">{{ $batch->status() }}</td>
        <td data-label="By">{{ $batch->creator?->name }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <nav class="actions pager" aria-label="Pages">
    @if ($batches->previousPageUrl())<a href="{{ $batches->previousPageUrl() }}">← Previous</a>@endif
    @if ($batches->nextPageUrl())<a href="{{ $batches->nextPageUrl() }}">Next →</a>@endif
  </nav>
@endif
@endsection
