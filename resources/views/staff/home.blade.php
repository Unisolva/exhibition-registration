{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Home')

@section('content')
<x-page-header title="Today" subtitle="Open exhibitions" />

@if ($exhibitions->isEmpty())
  <div class="card"><div class="card-body">
    <p class="mb-2">No exhibition is open for registration.</p>
    <a class="btn btn-primary" href="{{ route('exhibitions.index') }}">Exhibitions</a>
  </div></div>
@else
  {{-- M10 CO-3: one card per open exhibition. --}}
  <div class="row row-cards">
    @foreach ($exhibitions as $exhibition)
      @php($count = $counts[$exhibition->id] ?? null)
      @php($total = (int) ($count->total ?? 0))
      @php($today = (int) ($count->today ?? 0))
      @php($totalQr = (int) ($count->total_qr ?? 0))
      @php($todayQr = (int) ($count->today_qr ?? 0))
      <div class="col-12 col-xl-6">
        <section class="card" aria-labelledby="ex-{{ $exhibition->id }}">
          <div class="card-header">
            <h2 class="card-title" id="ex-{{ $exhibition->id }}">{{ $exhibition->name }} <span class="text-secondary fw-normal">· {{ $exhibition->starts_on->format('j M Y') }}</span></h2>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-6 col-md-3">
                <div class="text-secondary">Today</div>
                <div class="stat-value">{{ $today }}</div>
                <div class="stat-split">QR {{ $todayQr }} · staff {{ $today - $todayQr }}</div>
              </div>
              <div class="col-6 col-md-3">
                <div class="text-secondary">In total</div>
                <div class="stat-value">{{ $total }}</div>
                <div class="stat-split">QR {{ $totalQr }} · staff {{ $total - $totalQr }}</div>
              </div>
              @if ($exhibition->offers_package)
                <div class="col-6 col-md-3">
                  <div class="text-secondary">Packages today</div>
                  <div class="stat-value">{{ (int) ($count->packages_today ?? 0) }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="text-secondary">Packages in total</div>
                  <div class="stat-value">{{ (int) ($count->packages ?? 0) }}</div>
                  <div class="stat-split">not given {{ $total - (int) ($count->packages ?? 0) }}</div>
                </div>
              @endif
            </div>
          </div>
          <div class="card-footer">
            <a class="btn btn-outline-primary" href="{{ route('visitors.index', ['exhibition' => $exhibition->id]) }}"><x-icon name="users" /> Visitors at {{ $exhibition->name }}</a>
          </div>
        </section>
      </div>
    @endforeach
  </div>
@endif

@if ($odoo)
  <section class="card mt-3" aria-labelledby="odoo-title">
    <div class="card-header"><h2 class="card-title" id="odoo-title">Sending to Odoo</h2></div>
    <div class="card-body">
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
    </div>
  </section>
@endif
@endsection
