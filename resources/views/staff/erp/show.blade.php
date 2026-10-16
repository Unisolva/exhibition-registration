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
@foreach (['test', 'live'] as $kind)
  @if ($errors->getBag('block_'.$kind)->any())
    <div class="notice notice-err" role="alert"><a href="#block-{{ $kind }}">{{ strtoupper($kind) }} connection not saved</a>: please fix the fields marked below.</div>
  @endif
@endforeach

<form method="post" action="{{ route('erp.settings') }}" class="card">
  @csrf
  @method('PUT')
  <h2>Mode and version</h2>
  <div class="filter-grid">
    <x-field type="select" name="erp_mode" label="ERP mode" :value="old('erp_mode', $settings->get('erp_mode'))"
             :options="['none' => 'None (CSV export only)', 'odoo' => 'Odoo']" />
    <x-field type="select" name="odoo_version" label="Odoo version (shared by Test and Live)" :value="old('odoo_version', $settings->get('odoo_version'))"
             :options="collect($versions)->mapWithKeys(fn ($v) => [$v => 'Odoo '.$v])->all()" />
  </div>
  <h2>Lead settings</h2>
  <x-field name="lead_name_template" label="Lead name" hint="{exhibition} and {visitor_name} are replaced."
           :value="old('lead_name_template', $settings->get('lead_name_template'))" maxlength="200" />
  <div class="filter-grid">
    <x-field type="number" name="odoo_campaign_id" label="Campaign ID (optional)" min="1" :value="old('odoo_campaign_id', $settings->get('odoo_campaign_id'))" />
    <x-field type="number" name="odoo_source_id" label="Source ID (optional)" min="1" :value="old('odoo_source_id', $settings->get('odoo_source_id'))" />
    <x-field name="odoo_tag_ids" label="Tag IDs (comma-separated, optional)" :value="old('odoo_tag_ids', implode(', ', (array) $settings->get('odoo_tag_ids')))" />
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
          @php($bag = 'block_'.$kind)
          @php($typed = $errors->getBag($bag)->any())
          @php($keyHint = ['saved' => 'Saved (ends …'.$key['ends'].'). Leave empty to keep it, or enter a new key to replace it.', 'unreadable' => 'Key must be re-entered (the application key changed).'][$key['status']] ?? null)
          <x-field type="url" name="base_url" :id="$kind.'-url'" :bag="$bag" label="Odoo URL" hint="https://host, no path."
                   :value="$typed ? old('base_url') : $block?->base_url" dir="ltr" required />
          <x-field name="database" :id="$kind.'-db'" :bag="$bag" label="Database" :value="$typed ? old('database') : $block?->database" dir="ltr" required />
          <x-field name="login" :id="$kind.'-login'" :bag="$bag" label="User" hint="The login of a CRM-only Odoo user."
                   :value="$typed ? old('login') : $block?->login" dir="ltr" required />
          <x-field type="password" name="api_key" :id="$kind.'-key'" :bag="$bag" label="API key" dir="ltr" autocomplete="new-password" :hint="$keyHint" />
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
        <tr><td data-label="Item">{{ $item->id }}</td><td data-label="Visitor">{{ $item->visitor?->name }}</td><td data-label="State">{{ str_replace('_', ' ', $item->state) }}</td>
          <td data-label="Batch"><a href="{{ route('batches.show', $item->batch_id) }}">Batch #{{ $item->batch_id }}</a></td></tr>
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
