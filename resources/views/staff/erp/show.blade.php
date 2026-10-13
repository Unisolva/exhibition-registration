{{-- Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE. --}}
@extends('layouts.staff')

@section('title', 'ERP (Odoo)')

@section('content')
<h1>ERP (Odoo)</h1>

@if ($report)
  <div class="notice {{ $report['ok'] ? 'notice-ok' : 'notice-err' }}" role="status">
    {{ strtoupper($report['kind']) }} connection test: {{ $report['message'] }}
  </div>
@endif
@if ($errors->any())
  <div class="notice notice-err" role="alert">@foreach ($errors->all() as $message){{ $message }} @endforeach</div>
@endif

<form method="post" action="{{ route('erp.settings') }}" class="card">
  @csrf
  @method('PUT')
  <h2>Mode and version</h2>
  <div class="filter-grid">
    <div class="field">
      <label for="erp_mode">ERP mode</label>
      <select id="erp_mode" name="erp_mode">
        <option value="none" @selected($settings->get('erp_mode') === 'none')>None (CSV export only)</option>
        <option value="odoo" @selected($settings->get('erp_mode') === 'odoo')>Odoo</option>
      </select>
    </div>
    <div class="field">
      <label for="odoo_version">Odoo version <span class="hint">(shared by Test and Live)</span></label>
      <select id="odoo_version" name="odoo_version">
        @foreach ($versions as $version)
          <option value="{{ $version }}" @selected($settings->get('odoo_version') === $version)>Odoo {{ $version }}</option>
        @endforeach
      </select>
    </div>
  </div>
  <h2>Lead settings</h2>
  <div class="field">
    <label for="lead_name_template">Lead name <span class="hint">({exhibition} and {visitor_name} are replaced)</span></label>
    <input id="lead_name_template" name="lead_name_template" type="text" maxlength="200" value="{{ old('lead_name_template', $settings->get('lead_name_template')) }}">
  </div>
  <div class="filter-grid">
    <div class="field">
      <label for="odoo_campaign_id">Campaign ID <span class="hint">(optional)</span></label>
      <input id="odoo_campaign_id" name="odoo_campaign_id" type="number" min="1" value="{{ old('odoo_campaign_id', $settings->get('odoo_campaign_id')) }}">
    </div>
    <div class="field">
      <label for="odoo_source_id">Source ID <span class="hint">(optional)</span></label>
      <input id="odoo_source_id" name="odoo_source_id" type="number" min="1" value="{{ old('odoo_source_id', $settings->get('odoo_source_id')) }}">
    </div>
    <div class="field">
      <label for="odoo_tag_ids">Tag IDs <span class="hint">(comma-separated, optional)</span></label>
      <input id="odoo_tag_ids" name="odoo_tag_ids" type="text" value="{{ old('odoo_tag_ids', implode(', ', (array) $settings->get('odoo_tag_ids'))) }}">
    </div>
  </div>
  <p class="hint">Changing the version marks both connections untested and switches pushing off.</p>
  <div class="actions"><button type="submit" class="btn">Save</button></div>
</form>

@if ($settings->get('erp_mode') === 'odoo')
  <h2>Connections</h2>
  <form method="post" action="{{ route('erp.activate') }}" id="activate-form">@csrf</form>
  <div class="erp-blocks">
    @foreach ($blocks as $kind => $block)
      @php($key = $block?->keyStatus() ?? ['status' => 'none', 'ends' => null])
      <section class="card erp-block {{ $block?->is_active ? 'is-active' : '' }}" aria-labelledby="block-{{ $kind }}">
        <h3 id="block-{{ $kind }}">
          <label class="check">
            <input type="radio" name="kind" value="{{ $kind }}" form="activate-form" @checked($block?->is_active) @disabled(! $block)>
            {{ strtoupper($kind) }}
          </label>
          @if ($block?->is_active)<span class="badge">Active</span>@endif
        </h3>

        <form method="post" action="{{ route('erp.blocks.save', $kind) }}" autocomplete="off">
          @csrf
          @method('PUT')
          <div class="field">
            <label for="{{ $kind }}-url">Odoo URL <span class="hint">(https://, no path)</span></label>
            <input id="{{ $kind }}-url" name="base_url" type="url" dir="ltr" required value="{{ $block?->base_url }}">
          </div>
          <div class="field">
            <label for="{{ $kind }}-db">Database</label>
            <input id="{{ $kind }}-db" name="database" type="text" dir="ltr" required value="{{ $block?->database }}">
          </div>
          <div class="field">
            <label for="{{ $kind }}-login">User <span class="hint">(login of a CRM-only Odoo user)</span></label>
            <input id="{{ $kind }}-login" name="login" type="text" dir="ltr" required value="{{ $block?->login }}">
          </div>
          <div class="field">
            <label for="{{ $kind }}-key">API key</label>
            @if ($key['status'] === 'saved')
              <p class="hint">Saved (ends …{{ $key['ends'] }}). Leave empty to keep it, or enter a new key to replace it.</p>
            @elseif ($key['status'] === 'unreadable')
              <p class="error">Key must be re-entered (the application key changed).</p>
            @endif
            <input id="{{ $kind }}-key" name="api_key" type="password" dir="ltr" autocomplete="new-password" value="">
          </div>
          <p class="hint">Changing the URL or database clears the stored key.</p>
          <div class="actions"><button type="submit" class="btn btn-secondary">Save {{ strtoupper($kind) }}</button></div>
        </form>

        @if ($block)
          <form method="post" action="{{ route('erp.blocks.test', $kind) }}" class="spaced">
            @csrf
            <button type="submit" class="btn btn-secondary">Test connection</button>
            <span class="hint">
              @if ($block->last_test_at)
                Last test {{ $block->last_test_at->timezone($settings->get('display_timezone'))->format('Y-m-d H:i') }}:
                {{ $block->last_test_ok ? 'passed' : 'failed' }}@if ($block->last_test_server_version) (Odoo {{ $block->last_test_server_version }}, user {{ $block->last_test_uid }})@endif.
                @unless ($block->isTested()) Not valid since the last change. @endunless
              @else
                Never tested.
              @endif
            </span>
          </form>
        @endif
      </section>
    @endforeach
  </div>
  <div class="actions spaced">
    <button type="submit" class="btn" form="activate-form">Use the selected connection</button>
    <span class="hint">Allowed only after a passing test, and only when the active connection has no unfinished items.</span>
  </div>

  <h2>Pushing</h2>
  <form method="post" action="{{ route('erp.push') }}" class="card">
    @csrf
    @method('PUT')
    <p>Pushing is <strong>{{ $settings->get('erp_push_enabled') ? 'on' : 'off' }}</strong>.</p>
    <input type="hidden" name="enabled" value="{{ $settings->get('erp_push_enabled') ? '0' : '1' }}">
    <button type="submit" class="btn">{{ $settings->get('erp_push_enabled') ? 'Switch pushing off' : 'Switch pushing on' }}</button>
  </form>

  @if ($active && $unfinished->isNotEmpty())
    <h2>Unfinished items on the active connection</h2>
    <p>The connection cannot be switched or changed until these are finished, cancelled or reviewed.</p>
    <table class="stack-sm">
      <thead><tr><th>Item</th><th>Visitor</th><th>State</th><th>Batch</th></tr></thead>
      <tbody>
      @foreach ($unfinished as $item)
        <tr><td>{{ $item->id }}</td><td>{{ $item->visitor?->name }}</td><td>{{ str_replace('_', ' ', $item->state) }}</td>
          <td><a href="{{ route('batches.show', $item->batch_id) }}">#{{ $item->batch_id }}</a></td></tr>
      @endforeach
      </tbody>
    </table>
    <form method="post" action="{{ route('erp.cancel-waiting') }}" class="spaced">
      @csrf
      <button type="submit" class="btn btn-secondary">Cancel waiting items</button>
      <span class="hint">Cancels queued and waiting-to-retry items. Items being processed finish within one run; items needing review must be resolved on their batch.</span>
    </form>
  @endif
@endif
@endsection
