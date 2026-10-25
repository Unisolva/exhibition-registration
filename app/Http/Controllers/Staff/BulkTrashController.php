<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Visitors\VisitorLock;
use App\Visitors\VisitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * M10 CO-8: "Move selected to trash" on the visitor list (managers only). The
 * first step lists the names and says which will be skipped and why; the
 * second moves the rest to the trash in one transaction.
 */
class BulkTrashController extends Controller
{
    /** A page of the visitor list holds at most this many rows. */
    public const MAX = 200;

    public function __construct(private readonly VisitorService $visitors) {}

    public function review(Request $request): View|RedirectResponse
    {
        $ids = $this->ids($request);
        if ($ids === []) {
            return redirect()->route('visitors.index')->with('warning', 'Select at least one visitor.');
        }
        $visitors = Visitor::query()->whereKey($ids)->whereNull('trashed_at')->whereNull('deleted_at')->orderBy('name')->get();
        $locks = VisitorLock::causesFor($visitors->pluck('id')->all());

        return view('staff.visitors.bulk-trash-confirm', [
            'movable' => $visitors->reject(fn (Visitor $visitor) => isset($locks[$visitor->id]))->values(),
            'skipped' => $visitors->filter(fn (Visitor $visitor) => isset($locks[$visitor->id]))->values(),
            'locks' => $locks,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $ids = $this->ids($request);
        if ($ids === []) {
            return redirect()->route('visitors.index')->with('warning', 'Select at least one visitor.');
        }
        $result = $this->visitors->trashMany($ids, $request->user());
        $moved = count($result['trashed']);
        $skipped = count($result['skipped']);

        $status = $moved === 1 ? 'Moved 1 visitor to the trash.' : "Moved {$moved} visitors to the trash.";
        $status .= ' They can be restored for 30 days.';
        if ($skipped > 0) {
            $status .= ' '.($skipped === 1 ? '1 visitor was' : "{$skipped} visitors were").' skipped: locked or being sent to the ERP.';
        }

        return redirect()->route('visitors.index')->with('status', $status);
    }

    /** @return list<int> */
    private function ids(Request $request): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('visitor_ids', [])), fn (int $id) => $id > 0)));

        return array_slice($ids, 0, self::MAX);
    }
}
