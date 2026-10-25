{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', $exhibition->name)

@section('content')
<x-page-header :title="$exhibition->name">
  <a class="btn btn-primary" href="{{ route('visitors.index', ['exhibition' => $exhibition->id]) }}"><x-icon name="users" /> View visitors</a>
  <a class="btn btn-outline-primary" href="{{ route('visitors.export', ['exhibition' => $exhibition->id]) }}"><x-icon name="download" /> Export CSV</a>
</x-page-header>
<p>
  {{ $exhibition->starts_on->format('Y-m-d') }}@if ($exhibition->ends_on) – {{ $exhibition->ends_on->format('Y-m-d') }}@endif
  · @if ($exhibition->is_open)<span class="badge bg-success-lt">Registration open</span>@else<span class="badge bg-secondary-lt">Registration closed</span>@endif
  · Public form language: {{ $exhibition->default_locale === 'en' ? 'English' : 'Arabic' }}
  · Welcome package: {{ $exhibition->offers_package ? 'offered' : 'not offered' }}
</p>

{{-- M10 CO-4a: what happened at this exhibition so far. --}}
@php($total = (int) $summary->total)
<div class="row row-cards mb-3 exhibition-summary">
  <div class="col-6 col-md-3"><div class="card card-body"><div class="text-secondary">Registrations</div><div class="stat-value">{{ $total }}</div><div class="stat-split">QR {{ (int) $summary->qr }} · staff {{ $total - (int) $summary->qr }}</div></div></div>
  @if ($exhibition->offers_package)
    <div class="col-6 col-md-3"><div class="card card-body"><div class="text-secondary">Packages given</div><div class="stat-value">{{ (int) $summary->packages }}</div><div class="stat-split">not given {{ $total - (int) $summary->packages }}</div></div></div>
  @endif
</div>

<div class="card card-body qr-card">
  <div class="qr" role="img" aria-label="QR code for {{ $exhibition->publicUrl() }}">{!! $qr !!}</div>
  <div>
    <p>Registration page:<br><a href="{{ $exhibition->publicUrl() }}" class="url">{{ $exhibition->publicUrl() }}</a></p>
    <div class="actions">
      <a class="btn btn-outline-primary" href="{{ route('exhibitions.qr', $exhibition) }}">Download SVG</a>
      <a class="btn btn-outline-primary" href="{{ route('exhibitions.poster', $exhibition) }}">Print poster</a>
    </div>
  </div>
</div>

<div class="actions spaced">
  <a class="btn btn-outline-primary" href="{{ route('exhibitions.edit', $exhibition) }}">Edit name, dates, language and package</a>
  @if ($exhibition->is_open)
    <a class="btn btn-outline-primary" href="{{ route('exhibitions.close-confirm', $exhibition) }}">Close registration…</a>
  @else
    <form method="post" action="{{ route('exhibitions.open', $exhibition) }}">@csrf<button type="submit" class="btn btn-primary">Open registration</button></form>
  @endif
</div>
<p class="hint">Closing stops the public form for this exhibition only. Staff can still enter visitors, for example from paper forms.</p>
@endsection
