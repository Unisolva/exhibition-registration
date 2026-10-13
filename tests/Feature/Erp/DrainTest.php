<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\Drain;
use App\Erp\MockConnector;
use App\Models\ErpConnection;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §8 drain and §10.9 with MockConnector (the two-process case is in
 * ParallelDrainTest).
 */
class DrainTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    public function test_success_creates_one_lead_and_marks_both_rows(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create(['name' => 'Drain Success', 'region_code' => 'C']);
        $batch = $this->queue([$visitor]);
        $itemId = $batch->items()->value('id');

        $this->assertSame(['outcome' => 'ok', 'items' => 1, 'error_class' => null], $this->drain());

        $item = $this->item($itemId);
        $this->assertSame('succeeded', $item->state);
        $this->assertSame(1, $item->attempts);
        $sync = $this->sync($visitor, $connection);
        $this->assertSame('succeeded', $sync->state);
        $this->assertNull($sync->active_item_id);
        $this->assertSame($item->erp_lead_id, (int) $sync->erp_lead_id);

        $leads = MockConnector::leads();
        $this->assertCount(1, $leads);
        $sent = $leads[$item->erp_lead_id]['payload'];
        $this->assertSame('lead', $sent['type']);
        $this->assertFalse($sent['user_id']);
        $this->assertSame(65, $sent['country_id']);
        $this->assertArrayHasKey('state_id', $sent);
        $this->assertArrayNotHasKey('ext_ref', $sent);
        $this->assertArrayNotHasKey('_country_code', $sent);
        $this->assertStringContainsString('Ref: '.$sync->ext_ref, $sent['description']);
        // Search always comes first.
        $this->assertSame(['version', 'search', 'create'], array_column(MockConnector::calls(), 0));
    }

    public function test_auth_failure_disables_push_and_stops_the_run(): void
    {
        $this->readyToPush();
        $batch = $this->queue(Visitor::factory()->count(3)->create()->all());
        MockConnector::script('search', ['auth']);

        $result = $this->drain();

        $this->assertSame('stopped_auth', $result['outcome']);
        $this->assertSame(1, $result['items']);
        $states = $batch->items()->orderBy('id')->pluck('state')->all();
        $this->assertSame(['failed_final', 'queued', 'queued'], $states);
        $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
        $this->assertDatabaseHas('audit_events', ['action' => 'push_disabled', 'outcome' => 'automatic']);
        $this->assertStringContainsString('"reason":"auth"', DB::table('audit_events')->where('action', 'push_disabled')->value('meta'));
        $this->assertSame('skipped_disabled', $this->drain()['outcome']);
    }

    public function test_validation_failure_is_final(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        MockConnector::script('create', ['validation']);

        $this->drain();

        $this->assertSame('failed_final', $this->item($itemId)->state);
        $this->assertSame('validation', $this->item($itemId)->error_class);
        $this->assertSame('failed', $this->sync($visitor, $connection)->state);
        $this->assertNull($this->sync($visitor, $connection)->active_item_id);
        $this->assertTrue(app(Settings::class)->get('erp_push_enabled'));
    }

    public function test_429_and_5xx_back_off_and_become_final_after_five_attempts(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        MockConnector::script('create', ['rate_limited', 'http_5xx', 'network', 'timeout', 'http_5xx']);

        $expectedDelays = [60, 600, 3600, 21600];
        foreach ($expectedDelays as $attempt => $delay) {
            $this->travelTo(now()->startOfSecond());
            $this->drain();
            $item = $this->item($itemId);
            $this->assertSame('failed_retry', $item->state, "attempt {$attempt}");
            $this->assertSame($attempt + 1, $item->attempts);
            $this->assertSame($delay, (int) now()->diffInSeconds($item->next_attempt_at), "backoff after attempt {$attempt}");
            $this->assertSame('queued', $this->sync($visitor, $connection)->state);

            // Not due yet: the drain does nothing and contacts no one.
            $callsBefore = count(MockConnector::calls());
            $this->assertSame(['outcome' => 'ok', 'items' => 0, 'error_class' => null], $this->drain());
            $this->assertCount($callsBefore, MockConnector::calls());
            $this->makeDue($itemId);
        }

        $this->drain();
        $this->assertSame('failed_final', $this->item($itemId)->state);
        $this->assertSame(5, $this->item($itemId)->attempts);
        $this->assertSame('failed', $this->sync($visitor, $connection)->state);
        $this->assertCount(0, MockConnector::leads());
    }

    public function test_a_timeout_after_the_remote_create_is_adopted_by_the_next_search(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        MockConnector::script('create', ['timeout_after']);

        $this->drain();
        $this->assertSame('failed_retry', $this->item($itemId)->state);
        $this->assertCount(1, MockConnector::leads());

        $this->makeDue($itemId);
        $this->drain();

        $this->assertSame('succeeded', $this->item($itemId)->state);
        $this->assertCount(1, MockConnector::leads(), 'Exactly one remote lead.');
        $this->assertSame(array_key_first(MockConnector::leads()), $this->item($itemId)->erp_lead_id);
        $this->assertSame(1, collect(MockConnector::calls())->where(0, 'create')->count());
        $this->assertSame('succeeded', $this->sync($visitor, $connection)->state);
    }

    public function test_duplicate_references_go_to_needs_review_and_keep_the_visitor_locked(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        $ref = $this->sync($visitor, $connection)->ext_ref;
        MockConnector::seedLead("<p>Ref: {$ref}</p>");
        MockConnector::seedLead("<p>merged copy Ref: {$ref}</p>");

        $this->drain();

        $this->assertSame('needs_review', $this->item($itemId)->state);
        $this->assertSame('needs_review', $this->sync($visitor, $connection)->state);
        $this->assertSame($itemId, $this->sync($visitor, $connection)->active_item_id);
        $this->assertSame(0, collect(MockConnector::calls())->where(0, 'create')->count());
    }

    public function test_stale_claim_recovery_searches_before_creating(): void
    {
        $connection = $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        $ref = $this->sync($visitor, $connection)->ext_ref;
        // A previous run claimed the item, created the lead remotely and died.
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'processing', 'claimed_at' => now()->subMinutes(6), 'attempts' => 1]);
        $leadId = MockConnector::seedLead("<p>Ref: {$ref}</p>");

        $this->drain();

        $item = $this->item($itemId);
        $this->assertSame('succeeded', $item->state);
        $this->assertSame($leadId, $item->erp_lead_id);
        $this->assertCount(1, MockConnector::leads());
        $this->assertSame(0, collect(MockConnector::calls())->where(0, 'create')->count());
    }

    public function test_a_fresh_processing_claim_is_left_alone(): void
    {
        $this->readyToPush();
        $itemId = $this->queue([Visitor::factory()->create()])->items()->value('id');
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'processing', 'claimed_at' => now()->subMinutes(2)]);

        $this->assertSame(['outcome' => 'ok', 'items' => 0, 'error_class' => null], $this->drain());
        $this->assertSame('processing', $this->item($itemId)->state);
        $this->assertSame([], MockConnector::calls());
    }

    public function test_nothing_to_push_means_no_odoo_call(): void
    {
        $this->readyToPush();

        $this->assertSame('ok', $this->drain()['outcome']);
        $this->assertSame([], MockConnector::calls());
        $this->assertDatabaseHas('drain_runs', ['outcome' => 'ok', 'items_processed' => 0]);
    }

    public function test_a_version_mismatch_disables_push_without_touching_items(): void
    {
        $this->readyToPush();
        $itemId = $this->queue([Visitor::factory()->create()])->items()->value('id');
        MockConnector::setServerVersion('17.0');

        $this->assertSame('stopped_version', $this->drain()['outcome']);

        $this->assertSame('queued', $this->item($itemId)->state);
        $this->assertSame(0, $this->item($itemId)->attempts);
        $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
        $this->assertStringContainsString('"reason":"version"', DB::table('audit_events')->where('action', 'push_disabled')->value('meta'));
    }

    public function test_a_failed_version_read_is_an_error_that_changes_nothing(): void
    {
        $this->readyToPush();
        $itemId = $this->queue([Visitor::factory()->create()])->items()->value('id');
        MockConnector::script('version', ['network']);

        $this->assertSame(['outcome' => 'error', 'items' => 0, 'error_class' => 'network'], $this->drain());
        $this->assertSame('queued', $this->item($itemId)->state);
        $this->assertTrue(app(Settings::class)->get('erp_push_enabled'));
        $this->assertSame('ok', $this->drain()['outcome']);
        $this->assertSame('succeeded', $this->item($itemId)->state);
    }

    public function test_a_second_drain_skips_while_the_named_lock_is_held(): void
    {
        $this->readyToPush();
        $this->queue([Visitor::factory()->create()]);

        // Another session holds the lock, as a concurrent drain would.
        config(['database.connections.lock_holder' => config('database.connections.mariadb')]);
        $holder = DB::connection('lock_holder');
        $this->assertSame(1, (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS got', [Drain::LOCK_NAME])->got);

        try {
            $this->assertSame('skipped_locked', $this->drain()['outcome']);
        } finally {
            $holder->selectOne('SELECT RELEASE_LOCK(?) AS r', [Drain::LOCK_NAME]);
            $holder->disconnect();
        }
        $this->assertSame('ok', $this->drain()['outcome']);
    }

    public function test_country_without_a_known_state_drops_only_state_id(): void
    {
        $this->readyToPush();
        DB::table('regions')->where(['country_code' => 'EG', 'code' => 'C'])->update(['erp_code' => 'ZZ']);
        $itemId = $this->queue([Visitor::factory()->create(['region_code' => 'C'])])->items()->value('id');

        $this->drain();

        $sent = MockConnector::leads()[$this->item($itemId)->erp_lead_id]['payload'];
        $this->assertSame(65, $sent['country_id']);
        $this->assertArrayNotHasKey('state_id', $sent);
    }

    public function test_only_items_of_the_active_connection_are_processed(): void
    {
        $live = $this->readyToPush('live');
        $test = $this->testedConnection('test');
        $visitor = Visitor::factory()->create();
        $liveItem = $this->queue([$visitor], connection: $live)->items()->value('id');
        $testItem = $this->queue([Visitor::factory()->create()], connection: $test)->items()->value('id');

        $this->drain();

        $this->assertSame('succeeded', $this->item($liveItem)->state);
        $this->assertSame('queued', $this->item($testItem)->state);
        $this->assertSame(ErpConnection::active()->id, $live->id);
    }

    public function test_drain_lines_go_to_the_drain_log(): void
    {
        $this->readyToPush();
        $this->queue([Visitor::factory()->create(['name' => 'Logged Nowhere'])]);
        $log = storage_path('logs/drain-'.now()->format('Y-m-d').'.log');
        $before = is_file($log) ? filesize($log) : 0;

        $this->drain();

        $written = substr((string) file_get_contents($log), $before);
        $this->assertStringContainsString('drain finished', $written);
        $this->assertStringContainsString('item processed', $written);
        $this->assertStringNotContainsString('Logged Nowhere', $written);
    }
}
