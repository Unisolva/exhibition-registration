<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Fixtures for the browser tests: wipes and seeds the application's own
 * database, then prints a JSON file the Playwright tests read. One exhibition,
 * one staff user and one super admin per test project, so projects never share
 * rate limits, sessions or entries. ERP mode is Odoo with the TEST block
 * active (the busiest staff screens); nothing drains, so nothing is sent.
 * Invented data only.
 *
 * Wipes the database: refuses unless BROWSER_TESTS_ALLOW_WIPE=1, and always
 * refuses in production.
 *
 * Usage: BROWSER_TESTS_ALLOW_WIPE=1 php tests/Browser/setup.php > tests/Browser/fixtures.json
 */

use App\Enums\Role;
use App\Erp\PushService;
use App\Models\ErpConnection;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\OneTimePassword;
use App\Support\Settings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

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
$settings->set('public_registration_enabled', true);
$settings->set('erp_mode', 'odoo');
$settings->set('erp_push_enabled', true);

foreach (['test', 'live'] as $kind) {
    $connection = new ErpConnection(['kind' => $kind, 'base_url' => "https://{$kind}-erp.example.test", 'database' => "{$kind}-db", 'login' => 'crm-api@example.test']);
    $connection->api_key = "browser-{$kind}-key-WXYZ";
    $connection->is_active = $kind === 'test';
    $connection->changed_at = now()->subHour();
    $connection->last_test_at = now()->subMinutes(30);
    $connection->last_test_ok = true;
    $connection->last_test_server_version = '18.0';
    $connection->last_test_uid = 7;
    $connection->save();
}
$testConnection = ErpConnection::active();

// M9: a staff-only radio field (optional, so the Save & New tests need not choose).
FieldDefinition::query()->create([
    'key' => 'visitor_type', 'is_system' => false, 'type' => 'select', 'label_en' => 'Visitor type', 'label_ar' => '',
    'options' => [['value' => 'vip', 'label_en' => 'VIP', 'label_ar' => '', 'is_active' => true],
        ['value' => 'regular', 'label_en' => 'Regular', 'label_ar' => '', 'is_active' => true]],
    'is_required' => false, 'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true, 'audience' => 'staff', 'widget' => 'radio',
]);

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

    // M9: an exhibition that offers a welcome package.
    $package = new Exhibition(['name' => "Package Fair {$project}", 'starts_on' => now()->toDateString(), 'offers_package' => true,
        'package_note_ar' => 'هديتك في انتظارك في جناحنا.', 'package_note_en' => 'Your welcome gift is waiting at our stand.']);
    $package->created_by = $owner->id;
    $package->save();

    $adminPassword = OneTimePassword::generate(20);
    $admin = new User(['name' => "Browser Admin {$project}", 'email' => "browser-admin-{$project}@example.test", 'role' => Role::SuperAdmin, 'is_active' => true]);
    $admin->forceFill(['password' => $adminPassword, 'must_change_password' => false])->save();

    // Visitors for the manager screens: a possible duplicate pair, one in a batch (locked), one in the trash.
    $first = Visitor::factory()->at($exhibition)->create(['name' => 'Sample Visitor One', 'source' => 'public']);
    $duplicate = Visitor::factory()->at($exhibition)->create([
        'name' => 'Sample Visitor Two', 'source' => 'public',
        'phone_raw' => $first->phone_raw, 'phone_e164' => $first->phone_e164, 'phone_key' => $first->phone_key, 'possible_duplicate_of' => $first->id,
    ]);
    $queued = Visitor::factory()->at($exhibition)->create(['name' => 'Sample Visitor Queued', 'source' => 'public']);
    Visitor::factory()->at($exhibition)->trashed($admin)->create(['name' => 'Sample Visitor Trashed', 'source' => 'public']);
    // M9: QR registrations at the package exhibition, one already handed over.
    $packageVisitor = Visitor::factory()->at($package)->create(['name' => 'Package Visitor One', 'source' => 'public']);
    $receivedVisitor = Visitor::factory()->at($package)->create(['name' => 'Package Visitor Two', 'source' => 'public',
        'package_received_at' => now(), 'package_received_by' => $admin->id]);

    $batch = app(PushService::class)->createBatch($admin, $testConnection, [$queued->id], (string) Str::ulid())['batch'];

    $projects[$project] = [
        'exhibitionId' => $exhibition->id,
        'exhibitionToken' => $exhibition->public_token,
        'exhibitionName' => $exhibition->name,
        'staffEmail' => $staff->email,
        'staffPassword' => $password,
        'staffId' => $staff->id,
        'adminEmail' => $admin->email,
        'adminPassword' => $adminPassword,
        'visitorId' => $first->id,
        'duplicateVisitorId' => $duplicate->id,
        'lockedVisitorId' => $queued->id,
        'batchId' => $batch->id,
        'fieldId' => FieldDefinition::query()->where('key', 'occupation')->value('id'),
        'packageExhibitionId' => $package->id,
        'packageExhibitionToken' => $package->public_token,
        'packageExhibitionName' => $package->name,
        'packageCode' => $packageVisitor->package_code,
        'receivedPackageCode' => $receivedVisitor->package_code,
        'packageVisitorPhone' => $packageVisitor->phone_raw,
    ];
}

echo json_encode(['projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
