{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', $visitor->name)

@section('content')
<div class="page-header"><h1 class="page-title">{{ $visitor->name }}</h1></div>
@if ($lock)
  <div class="alert alert-warning notice notice-warn" role="status"><x-lock-icon /> {{ \App\Visitors\VisitorLock::message($lock) }}. It cannot be edited or deleted.</div>
@endif

<div class="card card-body">
  <dl class="details">
    <dt>Exhibition</dt><dd>{{ $visitor->exhibition?->name }}</dd>
    <dt>Registered</dt><dd>{{ $visitor->created_at->timezone($timezone)->format('Y-m-d H:i') }} · {{ $visitor->source === 'public' ? 'QR form' : 'Staff entry by '.($visitor->enteredBy?->name ?? '?') }}</dd>
    @foreach ($fields as $field)
      @php
        $value = match (true) {
            $field->key === 'name' => $visitor->name,
            $field->key === 'phone' => $visitor->phone_raw
                .($visitor->phone_e164 && $visitor->phone_e164 !== $visitor->phone_raw ? ' ('.$visitor->phone_e164.')' : '')
                .($visitor->phone_valid ? '' : ' · not a recognised number'),
            $field->key === 'email' => $visitor->email,
            $field->key === 'occupation', $field->type === 'select' => $field->choiceText($visitor, 'en'),
            $field->type === 'region' => $visitor->region_label,
            default => $visitor->extra[$field->key] ?? null,
        };
      @endphp
      @if ($field->is_active || ($value !== null && $value !== ''))
        <dt>{{ $field->label_en }}@unless ($field->is_active) <span class="hint">(inactive field)</span>@endunless</dt>
        <dd @if ($field->key === 'phone' || $field->key === 'email') dir="ltr" @endif>{{ $value === null || $value === '' ? '—' : $value }}</dd>
      @endif
    @endforeach
    @if ($visitor->possible_duplicate_of)
      <dt>Possible duplicate</dt>
      <dd>
        Same phone number registered earlier at this exhibition.
        @if ($showDuplicateLink)<a href="{{ route('visitors.show', $visitor->possible_duplicate_of) }}">See the earlier entry</a>@endif
      </dd>
    @endif
  </dl>
</div>

<div class="actions spaced">
  @if (! $lock)
    @can('update', $visitor)<a class="btn btn-outline-primary" href="{{ route('visitors.edit', $visitor) }}">Edit</a>@endcan
    @can('trash', $visitor)<a class="btn btn-outline-primary" href="{{ route('visitors.trash-confirm', $visitor) }}">Delete</a>@endcan
  @endif
  <a href="{{ route('visitors.index') }}">Back to the list</a>
</div>

@if ($visitor->exhibition?->offers_package || $visitor->package_received_at)
  {{-- M9 CO-10, CO-11: marking and undoing work on a locked visitor too. --}}
  @inject('packageService', 'App\Visitors\PackageService')
  <h2>Welcome package</h2>
  <div class="card card-body">
    <dl class="details">
      <dt>Package code</dt><dd class="code-text" dir="ltr">{{ $visitor->package_code }}</dd>
      <dt>Status</dt>
      <dd>
        @if ($visitor->package_received_at)
          Received {{ $visitor->package_received_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $visitor->packageReceivedBy?->name ?? '?' }}
        @else
          Not received
        @endif
      </dd>
    </dl>
    <div class="actions spaced">
      @if (! $visitor->package_received_at)
        <form method="post" action="{{ route('packages.give', $visitor->package_code) }}" data-once>@csrf<button type="submit" class="btn btn-primary">Mark package received</button></form>
      @elseif ($packageService->canUndo($visitor, auth()->user()))
        <form method="post" action="{{ route('packages.undo', $visitor->package_code) }}" data-once>@csrf<button type="submit" class="btn btn-outline-primary">Undo</button></form>
      @endif
    </div>
    @if ($packageEvents->isNotEmpty())
      <h3>Package log</h3>
      <ul class="package-log">
        @foreach ($packageEvents as $event)
          <li>{{ \Illuminate\Support\Carbon::parse($event->at, 'UTC')->timezone($timezone)->format('Y-m-d H:i') }} · {{ $event->action === 'package.given' ? 'Marked received' : 'Undone' }} by {{ $event->user_name ?? '?' }}</li>
        @endforeach
      </ul>
    @endif
  </div>
@endif

<h2>Edit log</h2>
@if ($edits->isEmpty())
  <p>Not edited since it was registered.</p>
@else
  <div class="card mb-3"><div class="table-responsive"><table class="table table-vcenter card-table stack-sm">
    <thead><tr><th>When</th><th>Who</th><th>Changes</th></tr></thead>
    <tbody>
    @foreach ($edits as $edit)
      <tr>
        <td data-label="When">{{ \Illuminate\Support\Carbon::parse($edit->at, 'UTC')->timezone($timezone)->format('Y-m-d H:i') }}</td>
        <td data-label="Who">{{ $edit->user_name }}</td>
        <td data-label="Changes">
          <ul class="changes">
          @foreach (json_decode($edit->changes, true) as $key => [$old, $new])
            <li><strong>{{ $fields->firstWhere('key', $key)?->label_en ?? $key }}</strong>: {{ $old === null || $old === '' ? '—' : $old }} → {{ $new === null || $new === '' ? '—' : $new }}</li>
          @endforeach
          </ul>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table></div></div>
@endif
@endsection

@push('scripts')
<script src="{{ \App\Support\Asset::url('app.js') }}" defer></script>
@endpush
