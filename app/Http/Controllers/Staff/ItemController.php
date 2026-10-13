<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Erp\PushService;
use App\Http\Controllers\Controller;
use App\Models\ErpPushItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Item actions on the batch screen (work order §8 push state table): Retry
 * failed, Mark reviewed (lead exists / send again).
 */
class ItemController extends Controller
{
    public function __construct(private readonly PushService $push) {}

    public function retry(Request $request, ErpPushItem $item): RedirectResponse
    {
        $this->push->retry($item->id, $request->user());

        return redirect()->route('batches.show', $item->batch_id)->with('status', 'Queued again with the current visitor details.');
    }

    public function review(Request $request, ErpPushItem $item): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:lead_exists,send_again'],
            'erp_lead_id' => ['required_if:decision,lead_exists', 'nullable', 'integer', 'min:1'],
        ]);

        $this->push->markReviewed($item->id, $request->user(), $data['decision'] === 'lead_exists' ? (int) $data['erp_lead_id'] : null);

        return redirect()->route('batches.show', $item->batch_id)->with('status', 'Review recorded.');
    }
}
