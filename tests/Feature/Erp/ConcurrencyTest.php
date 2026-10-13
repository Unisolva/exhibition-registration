<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\MockConnector;
use App\Erp\PushService;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\ProductSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §10.9 (two drains in parallel, two processes, same database) and
 * §10.11 (an edit and a batch creation racing on one visitor serialise on the
 * row lock). Child processes cannot see a rolled-back test transaction, so this
 * class works on a committed, freshly migrated database and leaves a fresh one.
 */
class ConcurrencyTest extends TestCase
{
    use PushFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => ProductSeeder::class, '--force' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => ProductSeeder::class, '--force' => true]);
        parent::tearDown();
    }

    private function artisanProcess(string ...$arguments): Process
    {
        return new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), null, null, 120);
    }

    public function test_two_drains_in_parallel_never_process_an_item_twice_or_create_twice(): void
    {
        $this->readyToPush();
        MockConnector::setDelayMs(150);
        $visitors = Visitor::factory()->count(12)->create()->all();
        $this->queue($visitors);

        $first = $this->artisanProcess('leads:drain', '--max-items=50', '--max-seconds=60');
        $second = $this->artisanProcess('leads:drain', '--max-items=50', '--max-seconds=60');
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        // Anything a skipped run left behind is finished by one more run.
        $this->artisanProcess('leads:drain', '--max-items=50', '--max-seconds=60')->run();

        $this->assertSame(12, ErpPushItem::query()->where('state', 'succeeded')->count());
        $this->assertSame(12, ErpPushItem::query()->where('attempts', 1)->count(), 'Every item was claimed exactly once.');
        $leads = MockConnector::leads();
        $this->assertCount(12, $leads, 'Exactly one remote lead per visitor.');
        $refs = array_map(fn ($lead) => preg_replace('/^.*Ref: (EXR-[0-9A-Z]{26}).*$/s', '$1', $lead['description']), $leads);
        $this->assertCount(12, array_unique($refs));
        $this->assertSame(12, collect(MockConnector::calls())->where(0, 'create')->count());

        $outcomes = DB::table('drain_runs')->pluck('outcome')->all();
        $this->assertContains('skipped_locked', $outcomes, 'The two runs overlapped and one stood aside.');
    }

    public function test_an_edit_holding_the_row_makes_batch_creation_wait_and_queue_the_edited_record(): void
    {
        $connection = $this->readyToPush();
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create(['name' => 'Before Edit', 'region_code' => 'C']);

        $child = new Process([PHP_BINARY, base_path('tests/Support/race-edit.php'), (string) $visitor->id, (string) $admin->id, 'After Edit', '5'], base_path(), null, null, 60);
        $child->start();
        $deadline = microtime(true) + 30;
        while (! str_contains($child->getOutput(), 'edited') && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertStringContainsString('edited', $child->getOutput(), $child->getErrorOutput());

        $started = microtime(true);
        $result = app(PushService::class)->createBatch($admin, $connection, [$visitor->id], (string) Str::ulid());
        $waited = microtime(true) - $started;
        $child->wait();

        $this->assertStringContainsString('committed', $child->getOutput());
        $this->assertGreaterThan(0.8, $waited, 'Batch creation waited for the edit to commit.');
        $this->assertSame(1, $result['queued']);
        $payload = ErpPushItem::query()->sole()->payload;
        $this->assertSame('After Edit', $payload['contact_name']);
        $this->assertSame($visitor->fresh()->name, $payload['contact_name'], 'The queued payload equals the stored record.');
    }

    public function test_a_batch_holding_the_row_makes_the_edit_wait_and_then_refuse_it(): void
    {
        $connection = $this->readyToPush();
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create(['name' => 'Queued Name', 'region_code' => 'C']);

        DB::beginTransaction();
        app(PushService::class)->createBatch($admin, $connection, [$visitor->id], (string) Str::ulid());

        $child = new Process([PHP_BINARY, base_path('tests/Support/race-edit.php'), (string) $visitor->id, (string) $admin->id, 'Too Late', '0'], base_path(), null, null, 60);
        $child->start();
        sleep(1);
        $this->assertTrue($child->isRunning(), 'The edit is waiting for the row lock.');
        DB::commit();
        $child->wait();

        $this->assertStringContainsString('locked:active', $child->getOutput(), $child->getErrorOutput());
        $this->assertSame('Queued Name', $visitor->fresh()->name);
        $this->assertSame('Queued Name', ErpPushItem::query()->sole()->payload['contact_name']);
        $this->assertSame(ErpConnection::active()->id, $connection->id);
    }
}
