{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($connection = $batch->connection)
@php($inFlight = $totals['queued'] + $totals['processing'] + $totals['failed_retry'] > 0)

@section('title', 'Batch #'.$batch->id)

@section('content')
<div class="page-header"><h1 class="page-title">Batch #{{ $batch->id }}</h1></div>
<div class="destination destination-{{ $connection->kind }}">
  <div class="destination-kind">{{ $connection->label() }}</div>
  <div dir="ltr">{{ $connection->base_url }} / {{ $connection->database }}</div>
</div>
<p>Created {{ $batch->created_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $batch->creator?->name }} · {{ $batch->status() }}</p>

<ul class="totals" data-batch-status="{{ route('batches.status', $batch) }}" data-in-flight="{{ $inFlight ? '1' : '0' }}" data-totals="{{ json_encode($totals) }}">
  @foreach ($totals as $state => $count)
    @if ($count > 0)<li><span class="badge {{ \App\Models\ErpPushItem::stateBadge($state) }}">{{ \App\Models\ErpPushItem::stateLabel($state) }}: {{ $count }}</span></li>@endif
  @endforeach
</ul>

@if ($totals['queued'] + $totals['failed_retry'] > 0)
  <form method="post" action="{{ route('batches.cancel', $batch) }}" class="spaced">
    @csrf
    <button type="submit" class="btn btn-outline-primary">Cancel waiting items in this batch</button>
  </form>
@endif

<div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
  <thead><tr><th>Visitor</th><th>State</th><th>Attempts</th><th>Error</th><th>ERP lead</th><th><span class="hint">Actions</span></th></tr></thead>
  <tbody>
  @foreach ($items as $item)
    <tr>
      <td data-label="Visitor">
        @if ($item->visitor && $item->visitor->deleted_at === null)
          <a href="{{ route('visitors.show', $item->visitor_id) }}">{{ $item->visitor->name }}</a>
        @else
          <span class="hint">(deleted)</span>
        @endif
      </td>
      <td data-label="State"><span class="badge {{ \App\Models\ErpPushItem::stateBadge($item->state) }}">{{ \App\Models\ErpPushItem::stateLabel($item->state) }}</span></td>
      <td data-label="Attempts">{{ $item->attempts }}</td>
      <td data-label="Error">@if ($item->error_class)<strong>{{ \App\Models\ErpPushItem::errorLabel($item->error_class) }}</strong> <span class="hint">{{ $item->error_message }}</span>@endif</td>
      <td data-label="ERP lead">
        @if ($item->erp_lead_id)
          <a href="{{ $connection->base_url }}/web#id={{ $item->erp_lead_id }}&amp;model=crm.lead&amp;view_type=form" rel="noopener" target="_blank">{{ $item->erp_lead_id }}</a>
        @endif
      </td>
      <td>
        @if (\App\Erp\PushService::canRetry($item))
          <form method="post" action="{{ route('items.retry', $item) }}" class="inline">@csrf<button type="submit" class="btn btn-outline-primary">Retry</button></form>
        @endif
        @if ($item->state === 'needs_review')
          <form method="post" action="{{ route('items.review', $item) }}" class="inline">
            @csrf
            <input type="hidden" name="decision" value="lead_exists">
            <label for="lead-{{ $item->id }}">Lead ID in the ERP</label>
            <input id="lead-{{ $item->id }}" name="erp_lead_id" type="number" min="1" required class="form-control short">
            <button type="submit" class="btn btn-outline-primary">Mark reviewed: lead exists</button>
          </form>
          <form method="post" action="{{ route('items.review', $item) }}" class="inline">
            @csrf
            <input type="hidden" name="decision" value="send_again">
            <button type="submit" class="btn btn-outline-primary">Mark reviewed: send again</button>
          </form>
        @endif
      </td>
    </tr>
  @endforeach
  </tbody>
</table></div></div>
<p><a href="{{ route('batches.index') }}">All batches</a></p>
@endsection

@if ($inFlight)
  @push('scripts')
  <script src="{{ \App\Support\Asset::url('batch.js') }}" defer></script>
  @endpush
@endif
