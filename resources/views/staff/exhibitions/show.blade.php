{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', $exhibition->name)

@section('content')
<h1>{{ $exhibition->name }}</h1>
<p>
  {{ $exhibition->starts_on->format('Y-m-d') }}@if ($exhibition->ends_on) – {{ $exhibition->ends_on->format('Y-m-d') }}@endif
  · @if ($exhibition->is_open)<span class="badge">Registration open</span>@else<span class="badge badge-off">Registration closed</span>@endif
  · Public form language: {{ $exhibition->default_locale === 'en' ? 'English' : 'Arabic' }}
</p>

<div class="card qr-card">
  <div class="qr" role="img" aria-label="QR code for {{ $exhibition->publicUrl() }}">{!! $qr !!}</div>
  <div>
    <p>Registration page:<br><a href="{{ $exhibition->publicUrl() }}" class="url">{{ $exhibition->publicUrl() }}</a></p>
    <div class="actions">
      <a class="btn btn-secondary" href="{{ route('exhibitions.qr', $exhibition) }}">Download SVG</a>
      <a class="btn btn-secondary" href="{{ route('exhibitions.poster', $exhibition) }}">Print poster</a>
    </div>
  </div>
</div>

<div class="actions spaced">
  <a class="btn btn-secondary" href="{{ route('exhibitions.edit', $exhibition) }}">Edit name, dates and language</a>
  @if ($exhibition->is_open)
    <a class="btn btn-secondary" href="{{ route('exhibitions.close-confirm', $exhibition) }}">Close registration…</a>
  @else
    <form method="post" action="{{ route('exhibitions.open', $exhibition) }}">@csrf<button type="submit" class="btn">Open registration</button></form>
  @endif
</div>
<p class="hint">Closing stops the public form for this exhibition only. Staff can still enter visitors, for example from paper forms.</p>
@endsection
