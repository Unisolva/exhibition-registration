<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Child process for ConcurrencyTest: edits a visitor through VisitorService
 * inside an outer transaction and keeps it open for a while, so the parent
 * can race a batch creation against it.
 *
 * Usage: php race-edit.php <visitor_id> <user_id> <new_name> <hold_seconds>
 */

use App\Models\User;
use App\Models\Visitor;
use App\Visitors\VisitorLockedException;
use App\Visitors\VisitorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $visitorId, $userId, $name, $hold] = $argv;
$visitor = Visitor::query()->findOrFail((int) $visitorId);
$user = User::query()->findOrFail((int) $userId);

DB::beginTransaction();
try {
    app(VisitorService::class)->edit($visitor->id, [
        'name' => $name, 'phone' => $visitor->phone_raw, 'governorate' => (string) $visitor->region_code,
    ], $user);
    fwrite(STDOUT, "edited\n");
    sleep((int) $hold);
    DB::commit();
    fwrite(STDOUT, "committed\n");
} catch (VisitorLockedException $e) {
    DB::rollBack();
    fwrite(STDOUT, "locked:{$e->cause}\n");
}
