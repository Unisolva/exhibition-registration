{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@php($manager = auth()->user()->isManager())

@section('title', $manager ? 'Visitors' : 'My entries')

@section('content')
<div class="page-header"><h1 class="page-title">{{ $manager ? 'Visitors' : 'My entries' }}</h1></div>

<form method="get" action="{{ route('visitors.index') }}" class="card card-body filters" role="search">
  {{-- On phones the filters fold away; they open by themselves when one is set (M8 CO-19). --}}
  <details class="filter-details" @if ($activeFilters > 0) open @endif>
  <summary>Filters @if ($activeFilters > 0)<span class="hint">({{ $activeFilters }} active)</span>@endif</summary>
  <div class="filter-grid">
    <div class="field">
      <label for="q">Name or phone</label>
      <input class="form-control" id="q" name="q" type="text" value="{{ $filters['q'] }}" maxlength="100">
    </div>
    <div class="field">
      <label for="exhibition">Exhibition</label>
      <select class="form-select" id="exhibition" name="exhibition">
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
          <input class="form-control" id="occupation" name="occupation" type="text" value="{{ $filters['occupation'] }}" maxlength="100">
        @else
          <select class="form-select" id="occupation" name="occupation">
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
      <select class="form-select" id="region" name="region">
        <option value="">All</option>
        @foreach ($regions as $region)
          <option value="{{ $region->code }}" @selected($filters['region'] === $region->code)>{{ $region->name_en }}</option>
        @endforeach
      </select>
    </div>
    @if ($manager)
      <div class="field">
        <label for="source">Source</label>
        <select class="form-select" id="source" name="source">
          <option value="">All</option>
          <option value="public" @selected($filters['source'] === 'public')>QR form</option>
          <option value="staff" @selected($filters['source'] === 'staff')>Staff entry</option>
        </select>
      </div>
    @endif
    @if ($showErp)
      <div class="field">
        <label for="sync">ERP state</label>
        <select class="form-select" id="sync" name="sync">
          <option value="">All</option>
          @foreach (\App\Visitors\VisitorQuery::SYNC_STATES as $state)
            <option value="{{ $state }}" @selected($filters['sync'] === $state)>{{ \App\Visitors\VisitorQuery::syncLabel($state, $syncKind) }}</option>
          @endforeach
        </select>
      </div>
    @endif
    <div class="field">
      <label for="sort">Sort</label>
      <select class="form-select" id="sort" name="sort">
        <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
        <option value="oldest" @selected($filters['sort'] === 'oldest')>Oldest first</option>
        <option value="name" @selected($filters['sort'] === 'name')>Name A–Z</option>
        <option value="name_desc" @selected($filters['sort'] === 'name_desc')>Name Z–A</option>
      </select>
    </div>
  </div>
  @if ($showPackage)
    <div class="filter-grid">
      <x-field type="select" name="package" label="Package" :value="$filters['package']" placeholder="All"
               :options="['received' => 'Received', 'not_received' => 'Not received']" />
    </div>
  @endif
  <x-field type="checkbox" name="duplicate" label="Possible duplicates only" :checked="$filters['duplicate']" />
  <div class="actions">
    <button type="submit" class="btn btn-primary">Apply</button>
    <a href="{{ route('visitors.index') }}">Clear</a>
    @can('registrations.export')
      <a class="btn btn-outline-primary" href="{{ route('visitors.export', request()->except('cursor')) }}">Export CSV</a>
    @endcan
  </div>
  </details>
</form>

@if (count($page->items()) === 0)
  @if ($activeFilters > 0)
    <p>No visitors match these filters. <a href="{{ route('visitors.index') }}">Clear filters</a></p>
  @else
    <p>No visitors yet. Entries from the QR form and Save &amp; New appear here.</p>
  @endif
@else
  <p class="result-count" role="status">{{ $total }} {{ $total === 1 ? 'visitor' : 'visitors' }}</p>
  @if ($canPush)
  {{-- Selection lives only on this page: a new filter, search or page starts empty (work order §7). --}}
  <form method="post" action="{{ route('batches.preview') }}" autocomplete="off" data-select>
    @csrf
    <div class="select-bar">
      <label class="check"><input type="checkbox" data-select-all> Select all on this page</label>
      <span data-select-count aria-live="polite">0 selected</span>
      <button type="submit" class="btn btn-primary" data-select-submit>Send selected to Odoo…</button>
    </div>
  @endif
  <div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm visitors">
    <thead>
      <tr>@if ($canPush)<th><span class="hint">Select</span></th>@endif<th>Name</th><th>Phone</th><th>Exhibition</th><th>Registered</th>@if ($manager)<th>Source</th>@endif @if ($showErp)<th>ERP</th>@endif @if ($showPackage)<th>Package</th>@endif<th><span class="hint">Actions</span></th></tr>
    </thead>
    <tbody>
    @foreach ($page->items() as $visitor)
      @php($lock = $locks[$visitor->id] ?? null)
      <tr>
        @if ($canPush)
          <td class="select-cell"><label class="select-hit"><input type="checkbox" name="visitor_ids[]" value="{{ $visitor->id }}" aria-label="Select {{ $visitor->name }}" data-select-row></label></td>
        @endif
        <td data-label="Name">
          <a href="{{ route('visitors.show', $visitor) }}">{{ $visitor->name }}</a>
          @if ($visitor->possible_duplicate_of)<span class="badge bg-warning-lt">Possible duplicate</span>@endif
          @if ($lock)<span class="badge bg-secondary-lt badge-lock"><x-lock-icon /> Locked</span>@endif
        </td>
        <td data-label="Phone" dir="ltr">{{ $visitor->phone_raw }}</td>
        <td data-label="Exhibition">{{ $visitor->exhibition?->name }}</td>
        <td data-label="Registered">{{ $visitor->created_at->timezone($timezone)->format('Y-m-d H:i') }}</td>
        @if ($manager)<td data-label="Source">{{ $visitor->source === 'public' ? 'QR form' : 'Staff: '.($visitor->enteredBy?->name ?? '?') }}</td>@endif
        @if ($showErp)<td data-label="ERP">{{ \App\Visitors\VisitorQuery::syncLabel($visitor->sync_state, $syncKind) }}</td>@endif
        @if ($showPackage)<td data-label="Package">@if ($visitor->exhibition?->offers_package)@if ($visitor->package_received_at)<span class="badge bg-success-lt">Received</span>@else<span class="badge bg-secondary-lt">Not received</span>@endif@endif</td>@endif
        <td class="row-actions">
          @if (! $lock)
            @can('update', $visitor)<a href="{{ route('visitors.edit', $visitor) }}">Edit</a>@endcan
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div></div>
  @if ($canPush)
  </form>
  @endif
  <nav class="actions pager" aria-label="Pages">
    @if ($page->previousPageUrl())<a href="{{ $page->previousPageUrl() }}">← Previous</a>@endif
    @if ($page->nextPageUrl())<a href="{{ $page->nextPageUrl() }}">Next →</a>@endif
  </nav>
@endif
@endsection

@if ($canPush)
  @push('scripts')
  <script src="{{ \App\Support\Asset::url('select.js') }}" defer></script>
  @endpush
@endif
