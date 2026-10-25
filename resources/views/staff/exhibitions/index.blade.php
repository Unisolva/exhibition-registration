{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'Exhibitions')

@section('content')
<x-page-header title="Exhibitions">
  <a class="btn btn-primary" href="{{ route('exhibitions.create') }}"><x-icon name="plus" /> Add exhibition</a>
</x-page-header>
@if ($exhibitions->isEmpty())
  <p>No exhibitions yet.</p>
@else
  <div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
    <thead><tr><th>Name</th><th>Dates</th><th>Form language</th><th>Registration</th><th>Visitors</th></tr></thead>
    <tbody>
    @foreach ($exhibitions as $exhibition)
      <tr>
        <td data-label="Name"><a href="{{ route('exhibitions.show', $exhibition) }}">{{ $exhibition->name }}</a></td>
        <td data-label="Dates">{{ $exhibition->starts_on->format('Y-m-d') }}@if ($exhibition->ends_on) – {{ $exhibition->ends_on->format('Y-m-d') }}@endif</td>
        <td data-label="Form language">{{ $exhibition->default_locale === 'en' ? 'English' : 'Arabic' }}</td>
        <td data-label="Registration">@if ($exhibition->is_open)<span class="badge bg-success-lt">Open</span>@else<span class="badge bg-secondary-lt">Closed</span>@endif</td>
        <td data-label="Visitors">{{ $exhibition->visitors_count }}</td>
      </tr>
    @endforeach
    </tbody>
  </table></div></div>
@endif
@endsection
