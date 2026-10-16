{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Home')

@section('content')
<h1>Today</h1>

<h2>Open exhibitions</h2>
@if ($exhibitions->isEmpty())
  <p>No exhibition is open for registration. <a href="{{ route('exhibitions.index') }}">Exhibitions</a></p>
@else
  <table class="stack-sm">
    <thead><tr><th>Exhibition</th><th>Today</th><th>In total</th></tr></thead>
    <tbody>
    @foreach ($exhibitions as $exhibition)
      @php($count = $counts[$exhibition->id] ?? null)
      <tr>
        <td data-label="Exhibition"><a href="{{ route('visitors.index', ['exhibition' => $exhibition->id]) }}">{{ $exhibition->name }}</a></td>
        <td data-label="Today">{{ (int) ($count->today ?? 0) }}</td>
        <td data-label="In total">{{ (int) ($count->total ?? 0) }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
@endif

@if ($odoo)
  <h2>Sending to Odoo</h2>
  <ul class="overview">
    <li>
      @if ($needsReview > 0)
        <a href="{{ route('visitors.index', ['sync' => 'needs_review']) }}">{{ $needsReview }} {{ $needsReview === 1 ? 'item needs' : 'items need' }} checking in Odoo</a>
      @else
        Nothing needs checking in Odoo.
      @endif
    </li>
    <li>Sending to Odoo is <strong>{{ $sending ? 'on' : 'off' }}</strong>.</li>
    <li>
      @if ($active)
        Active connection: <strong>{{ strtoupper($active->kind) }}</strong> ({{ $active->base_url }} / {{ $active->database }})
      @else
        No active connection.
      @endif
      @can('system.manage') · <a href="{{ route('erp.show') }}">ERP settings</a>@endcan
    </li>
  </ul>
@endif
@endsection
