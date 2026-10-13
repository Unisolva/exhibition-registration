<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /healthz (work order §9): no login, no personal data. 503 when the
 * database is unreachable, or the last drain is older than 10 minutes while
 * items are waiting.
 */
class HealthController extends Controller
{
    public const STALE_DRAIN_SECONDS = 600;

    public function __invoke(): JsonResponse
    {
        try {
            $lastRun = DB::table('drain_runs')->max('started_at');
            $queued = DB::table('erp_push_items')->whereIn('state', ['queued', 'failed_retry', 'processing'])->count();
        } catch (Throwable) {
            return response()->json(['db' => false, 'drain_age_s' => null, 'queued' => null], 503);
        }

        $age = $lastRun === null ? null : (int) now()->diffInSeconds(Carbon::parse($lastRun, 'UTC'), true);
        $stale = $queued > 0 && ($age === null || $age > self::STALE_DRAIN_SECONDS);

        return response()->json(['db' => true, 'drain_age_s' => $age, 'queued' => $queued], $stale ? 503 : 200);
    }
}
