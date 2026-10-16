{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Exhibitions')

@section('content')
<h1>Exhibitions</h1>
<p><a class="btn" href="{{ route('exhibitions.create') }}">Add exhibition</a></p>
@if ($exhibitions->isEmpty())
  <p>No exhibitions yet.</p>
@else
  <table class="stack-sm">
    <thead><tr><th>Name</th><th>Dates</th><th>Form language</th><th>Registration</th><th>Visitors</th></tr></thead>
    <tbody>
    @foreach ($exhibitions as $exhibition)
      <tr>
        <td><a href="{{ route('exhibitions.show', $exhibition) }}">{{ $exhibition->name }}</a></td>
        <td>{{ $exhibition->starts_on->format('Y-m-d') }}@if ($exhibition->ends_on) – {{ $exhibition->ends_on->format('Y-m-d') }}@endif</td>
        <td>{{ $exhibition->default_locale === 'en' ? 'English' : 'Arabic' }}</td>
        <td>@if ($exhibition->is_open)<span class="badge">Open</span>@else<span class="badge badge-off">Closed</span>@endif</td>
        <td>{{ $exhibition->visitors_count }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>
@endif
@endsection
