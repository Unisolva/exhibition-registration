<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Erp\ConnectorFactory;
use App\Http\Controllers\Controller;
use App\Support\Release;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * System page (super admin, work order §7): what the server really runs and
 * whether pushing is healthy. Doubles as verification V4 after a deploy.
 */
class SystemController extends Controller
{
    public const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'fileinfo', 'tokenizer', 'xml', 'ctype', 'dom', 'iconv', 'json'];

    public function __invoke(Settings $settings): View
    {
        $lastRun = DB::table('drain_runs')->orderByDesc('id')->first();
        $oldestQueued = DB::table('erp_push_items')->whereIn('state', ['queued', 'failed_retry'])->min('created_at');
        $lastDisable = DB::table('audit_events')->where('action', 'push_disabled')->orderByDesc('id')->first(['at', 'meta']);

        return view('staff.system', [
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'disabledFunctions' => (string) ini_get('disable_functions'),
            'extensions' => collect(self::REQUIRED_EXTENSIONS)->mapWithKeys(fn ($ext) => [$ext => extension_loaded($ext)]),
            'release' => Release::current(),
            'lastRun' => $lastRun,
            'queued' => DB::table('erp_push_items')->whereIn('state', ['queued', 'failed_retry', 'processing'])->count(),
            'oldestQueued' => $oldestQueued,
            'needsReview' => DB::table('erp_push_items')->where('state', 'needs_review')->count(),
            'pushEnabled' => (bool) $settings->get('erp_push_enabled'),
            'erpMode' => (string) $settings->get('erp_mode'),
            'lastDisable' => $lastDisable,
            'mockRefused' => ConnectorFactory::mockRefused(),
            'timezone' => (string) $settings->get('display_timezone'),
        ]);
    }
}
