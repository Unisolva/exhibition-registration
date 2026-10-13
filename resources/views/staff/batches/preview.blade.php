{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Send to the ERP')

@section('content')
<div class="narrow">
  <h1>Send to the ERP</h1>

  @if (! $connection)
    <div class="notice notice-err" role="alert">No ERP connection is active. A super admin selects one on the ERP screen.</div>
  @else
    <div class="destination destination-{{ $connection->kind }}">
      <div class="destination-kind">{{ $connection->label() }}</div>
      <div dir="ltr">{{ $connection->base_url }} / {{ $connection->database }}</div>
    </div>

    <p><strong>{{ $counts['eligible'] }}</strong> of {{ count($ids) }} selected visitor(s) will be sent as leads.
      @if ($counts['skipped'])<br>{{ $counts['skipped'] }} will be skipped: already sent, already queued, or deleted.@endif</p>

    <h2>Fields sent</h2>
    <ul>
      @foreach ($fields as $field)<li>{{ $field->label_en }}</li>@endforeach
    </ul>
    <p class="hint">Each lead also carries the exhibition, the source, the registration time and a reference line, so a retry never creates it twice.</p>

    @if (! $pushEnabled)
      <div class="notice notice-err" role="alert">Pushing is switched off. A super admin switches it on on the ERP screen.</div>
    @elseif ($counts['eligible'] === 0)
      <div class="notice notice-warn" role="status">Nothing to send.</div>
    @else
      <form method="post" action="{{ route('batches.store') }}" data-once>
        @csrf
        <input type="hidden" name="batch_token" value="{{ $batchToken }}">
        @foreach ($ids as $id)<input type="hidden" name="visitor_ids[]" value="{{ $id }}">@endforeach
        <div class="actions">
          <button type="submit" class="btn">Push {{ $counts['eligible'] }} lead(s) to {{ $connection->label() }}</button>
          <a href="{{ route('visitors.index') }}">Cancel</a>
        </div>
      </form>
    @endif
  @endif
</div>
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
