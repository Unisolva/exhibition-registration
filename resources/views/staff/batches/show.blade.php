{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($connection = $batch->connection)
@php($inFlight = $totals['queued'] + $totals['processing'] + $totals['failed_retry'] > 0)

@section('title', 'Batch #'.$batch->id)

@section('content')
<h1>Batch #{{ $batch->id }}</h1>
<div class="destination destination-{{ $connection->kind }}">
  <div class="destination-kind">{{ $connection->label() }}</div>
  <div dir="ltr">{{ $connection->base_url }} / {{ $connection->database }}</div>
</div>
<p>Created {{ $batch->created_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $batch->creator?->name }} · {{ $batch->status() }}</p>

<ul class="totals" data-batch-status="{{ route('batches.status', $batch) }}" data-in-flight="{{ $inFlight ? '1' : '0' }}" data-totals="{{ json_encode($totals) }}">
  @foreach ($totals as $state => $count)
    @if ($count > 0)<li><span class="badge">{{ str_replace('_', ' ', $state) }}: {{ $count }}</span></li>@endif
  @endforeach
</ul>

@if ($totals['queued'] + $totals['failed_retry'] > 0)
  <form method="post" action="{{ route('batches.cancel', $batch) }}" class="spaced">
    @csrf
    <button type="submit" class="btn btn-secondary">Cancel waiting items in this batch</button>
  </form>
@endif

<table class="stack-sm">
  <thead><tr><th>Visitor</th><th>State</th><th>Attempts</th><th>Error</th><th>ERP lead</th><th><span class="hint">Actions</span></th></tr></thead>
  <tbody>
  @foreach ($items as $item)
    <tr>
      <td>
        @if ($item->visitor && $item->visitor->deleted_at === null)
          <a href="{{ route('visitors.show', $item->visitor_id) }}">{{ $item->visitor->name }}</a>
        @else
          <span class="hint">(deleted)</span>
        @endif
      </td>
      <td>{{ str_replace('_', ' ', $item->state) }}</td>
      <td>{{ $item->attempts }}</td>
      <td>@if ($item->error_class)<code>{{ $item->error_class }}</code> {{ $item->error_message }}@endif</td>
      <td>
        @if ($item->erp_lead_id)
          <a href="{{ $connection->base_url }}/web#id={{ $item->erp_lead_id }}&amp;model=crm.lead&amp;view_type=form" rel="noopener" target="_blank">{{ $item->erp_lead_id }}</a>
        @endif
      </td>
      <td>
        @if (\App\Erp\PushService::canRetry($item))
          <form method="post" action="{{ route('items.retry', $item) }}" class="inline">@csrf<button type="submit" class="btn btn-secondary">Retry</button></form>
        @endif
        @if ($item->state === 'needs_review')
          <form method="post" action="{{ route('items.review', $item) }}" class="inline">
            @csrf
            <input type="hidden" name="decision" value="lead_exists">
            <label for="lead-{{ $item->id }}">Lead ID in the ERP</label>
            <input id="lead-{{ $item->id }}" name="erp_lead_id" type="number" min="1" required class="short">
            <button type="submit" class="btn btn-secondary">Mark reviewed: lead exists</button>
          </form>
          <form method="post" action="{{ route('items.review', $item) }}" class="inline">
            @csrf
            <input type="hidden" name="decision" value="send_again">
            <button type="submit" class="btn btn-secondary">Mark reviewed: send again</button>
          </form>
        @endif
      </td>
    </tr>
  @endforeach
  </tbody>
</table>
<p><a href="{{ route('batches.index') }}">All batches</a></p>
@endsection

@if ($inFlight)
  @push('scripts')
  <script src="{{ \App\Support\Asset::url('batch.js') }}" defer></script>
  @endpush
@endif
