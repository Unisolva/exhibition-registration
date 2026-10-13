{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($manager = auth()->user()->isManager())

@section('title', $manager ? 'Visitors' : 'My entries')

@section('content')
<h1>{{ $manager ? 'Visitors' : 'My entries' }}</h1>

<form method="get" action="{{ route('visitors.index') }}" class="card filters" role="search">
  <div class="filter-grid">
    <div class="field">
      <label for="q">Name or phone</label>
      <input id="q" name="q" type="text" value="{{ $filters['q'] }}" maxlength="100">
    </div>
    <div class="field">
      <label for="exhibition">Exhibition</label>
      <select id="exhibition" name="exhibition">
        <option value="">All</option>
        @foreach ($exhibitions as $exhibition)
          <option value="{{ $exhibition->id }}" @selected($filters['exhibition'] === $exhibition->id)>{{ $exhibition->name }}</option>
        @endforeach
      </select>
    </div>
    @if ($occupation && $occupation->is_active)
      <div class="field">
        <label for="occupation">{{ $occupation->label_en }}</label>
        @if ($occupation->isFreeTextSelect())
          <input id="occupation" name="occupation" type="text" value="{{ $filters['occupation'] }}" maxlength="100">
        @else
          <select id="occupation" name="occupation">
            <option value="">All</option>
            @foreach ($occupation->options ?? [] as $option)
              <option value="{{ $option['value'] }}" @selected($filters['occupation'] === $option['value'])>{{ $option['label_en'] }}</option>
            @endforeach
          </select>
        @endif
      </div>
    @endif
    <div class="field">
      <label for="region">Governorate</label>
      <select id="region" name="region">
        <option value="">All</option>
        @foreach ($regions as $region)
          <option value="{{ $region->code }}" @selected($filters['region'] === $region->code)>{{ $region->name_en }}</option>
        @endforeach
      </select>
    </div>
    @if ($manager)
      <div class="field">
        <label for="source">Source</label>
        <select id="source" name="source">
          <option value="">All</option>
          <option value="public" @selected($filters['source'] === 'public')>QR form</option>
          <option value="staff" @selected($filters['source'] === 'staff')>Staff entry</option>
        </select>
      </div>
    @endif
    <div class="field">
      <label for="sync">ERP state</label>
      <select id="sync" name="sync">
        <option value="">All</option>
        @foreach (\App\Visitors\VisitorQuery::SYNC_STATES as $state)
          <option value="{{ $state }}" @selected($filters['sync'] === $state)>{{ str_replace('_', ' ', ucfirst($state)) }}</option>
        @endforeach
      </select>
    </div>
    <div class="field">
      <label for="sort">Sort</label>
      <select id="sort" name="sort">
        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
        <option value="name" @selected($filters['sort'] === 'name')>Name A–Z</option>
        <option value="name_desc" @selected($filters['sort'] === 'name_desc')>Name Z–A</option>
      </select>
    </div>
  </div>
  <label class="check"><input type="checkbox" name="duplicate" value="1" @checked($filters['duplicate'])> Possible duplicates only</label>
  <div class="actions">
    <button type="submit" class="btn">Apply</button>
    <a href="{{ route('visitors.index') }}">Clear</a>
    @can('registrations.export')
      <a class="btn btn-secondary" href="{{ route('visitors.export', request()->except('cursor')) }}">Export CSV</a>
    @endcan
  </div>
</form>

@if (count($page->items()) === 0)
  <p>No visitors match.</p>
@else
  <table class="stack-sm visitors">
    <thead>
      <tr><th>Name</th><th>Phone</th><th>Exhibition</th><th>Registered</th>@if ($manager)<th>Source</th>@endif<th>ERP</th><th><span class="hint">Actions</span></th></tr>
    </thead>
    <tbody>
    @foreach ($page->items() as $visitor)
      @php($lock = $locks[$visitor->id] ?? null)
      <tr>
        <td>
          <a href="{{ route('visitors.show', $visitor) }}">{{ $visitor->name }}</a>
          @if ($visitor->possible_duplicate_of)<span class="badge" title="Same phone number earlier at this exhibition">Possible duplicate</span>@endif
          @if ($lock)<span class="badge badge-lock" title="{{ \App\Visitors\VisitorLock::message($lock) }}">🔒 Locked</span>@endif
        </td>
        <td dir="ltr">{{ $visitor->phone_raw }}</td>
        <td>{{ $visitor->exhibition?->name }}</td>
        <td>{{ $visitor->created_at->timezone($timezone)->format('Y-m-d H:i') }}</td>
        @if ($manager)<td>{{ $visitor->source === 'public' ? 'QR form' : 'Staff: '.($visitor->enteredBy?->name ?? '?') }}</td>@endif
        <td>{{ str_replace('_', ' ', $visitor->sync_state) }}</td>
        <td class="row-actions">
          @if (! $lock)
            @can('update', $visitor)<a href="{{ route('visitors.edit', $visitor) }}">Edit</a>@endcan
            @can('trash', $visitor)<a href="{{ route('visitors.trash-confirm', $visitor) }}">Delete</a>@endcan
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <nav class="actions pager" aria-label="Pages">
    @if ($page->previousPageUrl())<a href="{{ $page->previousPageUrl() }}">← Previous</a>@endif
    @if ($page->nextPageUrl())<a href="{{ $page->nextPageUrl() }}">Next →</a>@endif
  </nav>
@endif
@endsection
