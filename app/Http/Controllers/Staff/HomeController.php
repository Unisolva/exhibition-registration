<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\Exhibition;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * M8 CO-16. Staff go straight to Save & New (it opens the exhibition kept in
 * the session, or the chooser). Admins and super admins get a short overview:
 * registrations today and in total per open exhibition and, in Odoo mode,
 * items needing review, whether sending is on and which connection is active.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Settings $settings): View|RedirectResponse
    {
        if (! $request->user()->isManager()) {
            return redirect()->route('staff.entry');
        }

        // "Today" is the display time zone's day, stored times are UTC.
        $timezone = (string) $settings->get('display_timezone');
        $dayStart = now($timezone)->startOfDay()->utc();
        $dayEnd = now($timezone)->endOfDay()->utc();

        $open = Exhibition::query()->where('is_open', true)->orderByDesc('starts_on')->orderByDesc('id')->get();
        $counts = DB::table('visitors')
            ->whereIn('exhibition_id', $open->pluck('id'))
            ->whereNull('trashed_at')->whereNull('deleted_at')
            ->groupBy('exhibition_id')
            ->selectRaw('exhibition_id, COUNT(*) AS total, SUM(created_at BETWEEN ? AND ?) AS today', [$dayStart, $dayEnd])
            ->get()->keyBy('exhibition_id');

        $odoo = $settings->get('erp_mode') === 'odoo';

        return view('staff.home', [
            'exhibitions' => $open,
            'counts' => $counts,
            'odoo' => $odoo,
            'needsReview' => $odoo ? ErpPushItem::query()->where('state', 'needs_review')->count() : 0,
            'sending' => (bool) $settings->get('erp_push_enabled'),
            'active' => $odoo ? ErpConnection::active() : null,
        ]);
    }
}
