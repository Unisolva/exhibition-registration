<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use App\Support\Settings;
use App\Visitors\VisitorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Work order §7 "Trash" (D12): admins and super admins restore; only a super
 * admin deletes permanently, with a typed confirmation. Housekeeping deletes
 * whatever has been in the trash for more than 30 days.
 */
class TrashController extends Controller
{
    public const DAYS = 30;

    public const CONFIRM_WORD = 'DELETE';

    public function __construct(private readonly VisitorService $visitors) {}

    public function index(Settings $settings): View
    {
        $visitors = Visitor::query()
            ->whereNotNull('trashed_at')->whereNull('deleted_at')
            ->with('exhibition', 'trashedBy')
            ->orderByDesc('trashed_at')->orderByDesc('id')
            ->paginate(50);

        return view('staff.trash.index', [
            'visitors' => $visitors,
            'timezone' => (string) $settings->get('display_timezone'),
        ]);
    }

    public function restore(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->ensureTrashed($visitor);
        Gate::authorize('trash', $visitor);

        $this->visitors->restore($visitor->id, $request->user());

        return redirect()->route('trash.index')->with('status', 'Restored. The visitor is back in the list.');
    }

    public function destroy(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->ensureTrashed($visitor);
        Gate::authorize('forceDelete', $visitor);

        if ($request->input('confirm') !== self::CONFIRM_WORD) {
            throw ValidationException::withMessages(['confirm' => 'Type '.self::CONFIRM_WORD.' to delete permanently.'])
                ->errorBag('delete'.$visitor->id);
        }

        $this->visitors->erase($visitor->id, $request->user());

        return redirect()->route('trash.index')->with('status', 'Deleted permanently. The personal details are gone.');
    }

    private function ensureTrashed(Visitor $visitor): void
    {
        if ($visitor->trashed_at === null || $visitor->deleted_at !== null) {
            throw new NotFoundHttpException;
        }
    }
}
