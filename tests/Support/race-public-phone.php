<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Child process for DuplicatePhoneTest (M9 CO-7): plays the first of two
 * simultaneous public registrations with one phone number. It takes the
 * app's phone lock, holds it, then inserts its visitor and releases, so the
 * parent's registration of the same number has to wait for it.
 *
 * Usage: php race-public-phone.php <exhibition_id> <phone_key> <hold_seconds>
 */

use App\Models\Visitor;
use App\Registration\PhoneLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $exhibitionId, $phoneKey, $hold] = $argv;
$lock = PhoneLock::name((int) $exhibitionId, $phoneKey);

if (! PhoneLock::acquire($lock, 1)) {
    fwrite(STDOUT, "no-lock\n");
    exit(1);
}
fwrite(STDOUT, "locked\n");
sleep((int) $hold);
Visitor::factory()->create([
    'exhibition_id' => (int) $exhibitionId, 'name' => 'First Of Two', 'source' => 'public',
    'phone_raw' => $phoneKey, 'phone_e164' => $phoneKey, 'phone_key' => $phoneKey, 'submission_token' => (string) Str::ulid(),
]);
fwrite(STDOUT, "inserted\n");
PhoneLock::release($lock);
fwrite(STDOUT, "released\n");
