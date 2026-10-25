{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
{{-- M9 CO-9: name, code, exhibition, registration time, source, the duplicate warning and the package status. Nothing more. --}}
@inject('packageService', 'App\Visitors\PackageService')
@php($timezone = (string) app(\App\Support\Settings::class)->get('display_timezone'))
<section class="card card-body package-card" aria-labelledby="package-{{ $visitor->package_code }}">
  <h2 id="package-{{ $visitor->package_code }}">{{ $visitor->name }}</h2>
  <dl class="details">
    <dt>Package code</dt><dd class="code-text" dir="ltr">{{ $visitor->package_code }}</dd>
    <dt>Exhibition</dt><dd>{{ $visitor->exhibition->name }}</dd>
    <dt>Registered</dt><dd>{{ $visitor->created_at->timezone($timezone)->format('Y-m-d H:i') }}</dd>
    <dt>Source</dt><dd>{{ $visitor->source === 'public' ? 'QR form' : 'Staff entry' }}</dd>
  </dl>
  @if ($visitor->possible_duplicate_of)
    <div class="alert alert-warning notice notice-warn" role="note">This phone number registered earlier at this exhibition. Check before handing over a second package.</div>
  @endif
  @if ($visitor->package_received_at)
    <div class="alert alert-success notice notice-ok package-received" role="status">
      <strong>Package already received</strong><br>
      {{ $visitor->package_received_at->timezone($timezone)->format('Y-m-d H:i') }} by {{ $visitor->packageReceivedBy?->name ?? '?' }}
    </div>
    @if ($packageService->canUndo($visitor, auth()->user()))
      <form method="post" action="{{ route('packages.undo', $visitor->package_code) }}" data-once>
        @csrf
        <button type="submit" class="btn btn-outline-primary">Undo</button>
        @unless (auth()->user()->isManager())<span class="hint">Possible for 10 minutes after marking.</span>@endunless
      </form>
    @endif
  @else
    <p>Package <strong>not received</strong> yet.</p>
    <form method="post" action="{{ route('packages.give', $visitor->package_code) }}" data-once>
      @csrf
      <button type="submit" class="btn btn-primary">Mark package received</button>
    </form>
  @endif
</section>
