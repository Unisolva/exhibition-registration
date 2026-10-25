{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'System')

@section('content')
<div class="page-header"><h1 class="page-title">System</h1></div>

@if ($mockRefused)
  <div class="alert alert-danger notice notice-err" role="alert">ERP_MOCK is set while APP_ENV is production. The mock is refused; remove ERP_MOCK from .env.</div>
@endif

<div class="card card-body">
  <dl class="details">
    <dt>Release</dt><dd>{{ $release['version'] }}@if ($release['id']) · release {{ $release['id'] }}@endif @if ($release['commit']) · commit {{ $release['commit'] }}@endif</dd>
    <dt>PHP</dt><dd>{{ $php }} ({{ $sapi }})</dd>
    <dt>Disabled functions</dt><dd><code>{{ $disabledFunctions !== '' ? $disabledFunctions : '(none)' }}</code></dd>
    <dt>Extensions</dt>
    <dd>
      @foreach ($extensions as $extension => $loaded)
        <span class="badge {{ $loaded ? 'bg-success-lt' : 'bg-danger-lt' }}">{{ $extension }}{{ $loaded ? '' : ' missing' }}</span>
      @endforeach
    </dd>
  </dl>
</div>

<h2>Sending to the ERP</h2>
<div class="card card-body">
  <dl class="details">
    <dt>ERP mode</dt><dd>{{ $erpMode }}</dd>
    <dt>Sending to Odoo</dt>
    <dd>
      {{ $pushEnabled ? 'on' : 'off' }}
      @if (! $pushEnabled && $lastDisable)
        @php($meta = json_decode((string) $lastDisable->meta, true) ?: [])
        · last switched off {{ \Illuminate\Support\Carbon::parse($lastDisable->at, 'UTC')->timezone($timezone)->format('Y-m-d H:i') }}, reason: {{ $meta['reason'] ?? 'unknown' }}
      @endif
    </dd>
    <dt>Last drain run</dt>
    <dd>
      @if ($lastRun)
        {{ (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastRun->started_at, 'UTC'), true) }} s ago · {{ $lastRun->outcome ?? 'running' }}@if ($lastRun->error_class) ({{ $lastRun->error_class }})@endif · {{ $lastRun->items_processed }} item(s)
      @else
        never
      @endif
    </dd>
    <dt>Waiting items</dt><dd>{{ $queued }}@if ($oldestQueued) · oldest {{ (int) now()->diffInMinutes(\Illuminate\Support\Carbon::parse($oldestQueued, 'UTC'), true) }} min @endif</dd>
    <dt>Needs review</dt><dd>{{ $needsReview }}</dd>
  </dl>
</div>
@endsection
