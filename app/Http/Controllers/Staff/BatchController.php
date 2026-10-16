<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Erp\PayloadBuilder;
use App\Erp\PushService;
use App\Http\Controllers\Controller;
use App\Models\ErpBatch;
use App\Models\ErpConnection;
use App\Models\FieldDefinition;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Push confirm, batch creation and the batch screen (super admin and admin,
 * work order §7). Selection travels as visitor IDs in the POST body.
 */
class BatchController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PushService $push,
    ) {}

    public function index(): View
    {
        return view('staff.batches.index', [
            'batches' => ErpBatch::query()->with('connection', 'creator')->orderByDesc('id')->paginate(30),
        ]);
    }

    public function preview(Request $request, PayloadBuilder $payloads): View|RedirectResponse
    {
        $ids = $this->selectedIds($request);
        if ($ids === []) {
            return redirect()->route('visitors.index')->with('status', 'Select at least one visitor first.');
        }

        $connection = ErpConnection::active();

        return view('staff.batches.preview', [
            'ids' => $ids,
            'connection' => $connection,
            'counts' => $connection ? $this->push->preview($ids, $connection) : ['eligible' => 0, 'skipped' => count($ids)],
            'fields' => FieldDefinition::query()->whereIn('key', $payloads->fieldKeys())->orderBy('sort_order')->get(),
            'pushEnabled' => (bool) $this->settings->get('erp_push_enabled'),
            'batchToken' => (string) Str::ulid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['batch_token' => ['required', 'string', 'size:26']]);
        $ids = $this->selectedIds($request);
        $connection = ErpConnection::active();

        if (! $this->settings->get('erp_push_enabled') || $connection === null) {
            return redirect()->route('visitors.index')->with('status', 'Sending to Odoo is off. Nothing was sent.');
        }

        $result = $this->push->createBatch($request->user(), $connection, $ids, strtoupper((string) $request->input('batch_token')));
        if ($result['batch'] === null) {
            return redirect()->route('visitors.index')->with('status', 'Nothing to send: every selected visitor was already sent or is already queued.');
        }

        $message = $result['replay']
            ? 'This batch was already created.'
            : $result['queued'].' '.Str::plural('visitor', $result['queued']).' queued'.($result['skipped'] ? ", {$result['skipped']} skipped" : '').'.';

        return redirect()->route('batches.show', $result['batch'])->with('status', $message);
    }

    public function show(ErpBatch $batch): View
    {
        $items = $batch->items()->with('visitor:id,name,deleted_at')->orderBy('id')->get();

        return view('staff.batches.show', [
            'batch' => $batch->load('connection', 'creator'),
            'items' => $items,
            'totals' => $batch->totals(),
            'timezone' => (string) $this->settings->get('display_timezone'),
        ]);
    }

    /** Small JSON for the 15-second poll while items are queued or processing. */
    public function status(ErpBatch $batch): JsonResponse
    {
        $totals = $batch->totals();

        return response()->json([
            'totals' => $totals,
            'active' => $totals['queued'] + $totals['processing'] + $totals['failed_retry'] > 0,
        ]);
    }

    public function cancel(Request $request, ErpBatch $batch): RedirectResponse
    {
        $ids = $batch->items()->whereIn('state', ['queued', 'failed_retry'])->pluck('id')->all();
        $count = $this->push->cancel($ids, $request->user());

        return redirect()->route('batches.show', $batch)->with('status', $count.' waiting '.Str::plural('item', $count).' cancelled.');
    }

    /**
     * @return list<int>
     */
    private function selectedIds(Request $request): array
    {
        $request->validate([
            'visitor_ids' => ['required', 'array', 'max:'.PushService::MAX_PER_BATCH],
            'visitor_ids.*' => ['integer', 'min:1'],
        ]);

        return array_values(array_unique(array_map('intval', (array) $request->input('visitor_ids'))));
    }
}
