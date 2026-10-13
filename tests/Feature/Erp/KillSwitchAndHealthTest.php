<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\ConnectorFactory;
use App\Models\ErpBatch;
use App\Models\ErpConnection;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §10.10 (kill switch and modes), §9 /healthz and the System page.
 */
class KillSwitchAndHealthTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    public function test_push_switched_off_stops_the_drain_and_batch_creation(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        app(Settings::class)->set('erp_push_enabled', false);

        $this->assertSame('skipped_disabled', $this->drain()['outcome']);
        $this->assertSame('queued', $this->item($itemId)->state);

        $other = Visitor::factory()->create();
        $this->actingAs($admin)->post('/staff/batches', ['batch_token' => (string) Str::ulid(), 'visitor_ids' => [$other->id]])
            ->assertRedirect(route('visitors.index'))->assertSessionHas('status', 'Pushing is switched off. Nothing was sent.');
        $this->actingAs($admin)->post('/staff/batches/preview', ['visitor_ids' => [$other->id]])->assertOk()->assertSee('Pushing is switched off');
        $this->assertSame(1, ErpBatch::query()->count());
    }

    public function test_erp_mode_none_stops_the_drain_and_hides_the_push_screens(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $batch = $this->queue([$visitor]);
        app(Settings::class)->set('erp_mode', 'none');

        $this->assertSame('skipped_disabled', $this->drain()['outcome']);
        foreach (['/staff/batches', "/staff/batches/{$batch->id}"] as $url) {
            $this->actingAs($admin)->get($url)->assertNotFound();
        }
        $this->actingAs($admin)->post('/staff/batches/preview', ['visitor_ids' => [$visitor->id]])->assertNotFound();
        $this->actingAs($admin)->post('/staff/batches', ['batch_token' => (string) Str::ulid(), 'visitor_ids' => [$visitor->id]])->assertNotFound();
        $this->actingAs($admin)->get('/staff/visitors')->assertOk()->assertDontSee('data-select-row', false)->assertDontSee('Batches');
    }

    public function test_the_mock_is_refused_in_production(): void
    {
        $this->readyToPush();
        $this->queue([Visitor::factory()->create()]);
        $this->app['env'] = 'production';

        try {
            $this->assertTrue(ConnectorFactory::mockRefused());
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ERP_MOCK=true is refused when APP_ENV=production.');
            app(ConnectorFactory::class)->for(ErpConnection::active());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_a_drain_in_production_with_the_mock_set_is_an_error_and_sends_nothing(): void
    {
        $this->readyToPush();
        $itemId = $this->queue([Visitor::factory()->create()])->items()->value('id');
        $this->app['env'] = 'production';

        try {
            $this->assertSame('error', $this->drain()['outcome']);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame('queued', $this->item($itemId)->state);
    }

    public function test_the_system_page_warns_when_the_mock_is_set_in_production(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->get('/staff/system')->assertOk()->assertSee(PHP_VERSION)->assertDontSee('ERP_MOCK is set');

        $this->app['env'] = 'production';
        try {
            $this->actingAs($super)->get('/staff/system')->assertOk()->assertSee('ERP_MOCK is set while APP_ENV is production');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_healthz_reports_db_drain_age_and_queue_without_a_session(): void
    {
        // The 503 below is the expected alarm.
        $this->allowServerErrors = true;

        $response = $this->get('/healthz')->assertOk();
        $this->assertSame(['db' => true, 'drain_age_s' => null, 'queued' => 0], $response->json());
        $this->assertSame([], $response->headers->getCookies(), 'No session is started.');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, DB::table('sessions')->count());

        $this->readyToPush();
        $this->queue([Visitor::factory()->create(['name' => 'Private Person'])]);
        DB::table('drain_runs')->insert(['started_at' => now()->subMinutes(11), 'items_processed' => 0, 'outcome' => 'ok']);

        $stale = $this->get('/healthz')->assertStatus(503);
        $this->assertSame(1, $stale->json('queued'));
        $this->assertGreaterThan(600, $stale->json('drain_age_s'));
        $stale->assertDontSee('Private Person');

        $this->drain();
        $this->get('/healthz')->assertOk()->assertJson(['queued' => 0]);
    }

    public function test_healthz_answers_404_on_a_wrong_host(): void
    {
        $this->get('http://other-site.example/healthz')->assertNotFound();
    }
}
