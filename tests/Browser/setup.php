<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Fixtures for the browser tests: wipes and seeds the application's own
 * database, then prints a JSON file the Playwright tests read. One exhibition
 * and one staff user per test project, so projects never share rate limits,
 * sessions or entries. Invented data only.
 *
 * Wipes the database: refuses unless BROWSER_TESTS_ALLOW_WIPE=1, and always
 * refuses in production.
 *
 * Usage: BROWSER_TESTS_ALLOW_WIPE=1 php tests/Browser/setup.php > tests/Browser/fixtures.json
 */

use App\Enums\Role;
use App\Models\Exhibition;
use App\Models\User;
use App\Support\OneTimePassword;
use App\Support\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (getenv('BROWSER_TESTS_ALLOW_WIPE') !== '1' || app()->environment('production')) {
    fwrite(STDERR, "Refused: this wipes the database. Set BROWSER_TESTS_ALLOW_WIPE=1 (never in production).\n");
    exit(1);
}

Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

$settings = app(Settings::class);
$settings->set('org_name_en', 'Sample Agri Company');
$settings->set('org_name_ar', 'شركة زراعية تجريبية');
$settings->set('erp_mode', 'none');
$settings->set('public_registration_enabled', true);

$owner = new User(['name' => 'Browser Test Owner', 'email' => 'browser-owner@example.test', 'role' => Role::SuperAdmin, 'is_active' => true]);
$owner->forceFill(['password' => OneTimePassword::generate(24), 'must_change_password' => false])->save();

$projects = [];
foreach (['ar-360', 'en-360', 'ar-1280', 'en-1280'] as $project) {
    $exhibition = new Exhibition(['name' => "Browser Test Fair {$project}", 'starts_on' => now()->toDateString(), 'ends_on' => now()->addDays(2)->toDateString()]);
    $exhibition->created_by = $owner->id;
    $exhibition->save();

    $password = OneTimePassword::generate(20);
    $staff = new User(['name' => "Browser Staff {$project}", 'email' => "browser-staff-{$project}@example.test", 'role' => Role::Staff, 'is_active' => true]);
    $staff->forceFill(['password' => $password, 'must_change_password' => false])->save();

    $projects[$project] = [
        'exhibitionId' => $exhibition->id,
        'exhibitionToken' => $exhibition->public_token,
        'exhibitionName' => $exhibition->name,
        'staffEmail' => $staff->email,
        'staffPassword' => $password,
    ];
}

echo json_encode(['projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
