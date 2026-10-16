{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', $visitor->name)

@section('content')
<h1>{{ $visitor->name }}</h1>
@if ($lock)
  <div class="notice notice-warn" role="status">🔒 {{ \App\Visitors\VisitorLock::message($lock) }}. It cannot be edited or deleted.</div>
@endif

<div class="card">
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
            $field->key === 'occupation' => $field->optionLabel($visitor->occupation, 'en'),
            $field->type === 'region' => $visitor->region_label,
            $field->type === 'select' => $field->optionLabel($visitor->extra[$field->key] ?? null, 'en'),
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
    @can('update', $visitor)<a class="btn btn-secondary" href="{{ route('visitors.edit', $visitor) }}">Edit</a>@endcan
    @can('trash', $visitor)<a class="btn btn-secondary" href="{{ route('visitors.trash-confirm', $visitor) }}">Delete</a>@endcan
  @endif
  <a href="{{ route('visitors.index') }}">Back to the list</a>
</div>

<h2>Edit log</h2>
@if ($edits->isEmpty())
  <p>Not edited since it was registered.</p>
@else
  <table class="stack-sm">
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
  </table>
@endif
@endsection
