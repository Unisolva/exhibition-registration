<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\ConnectionManager;
use App\Erp\MockConnector;
use App\Erp\PushService;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §7 "Odoo connections", §10.12 and §10.15.
 */
class ConnectionsTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    private const SECRET_KEY = 'secret-key-do-not-leak-9f8e7d6c';

    private function super(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function odooMode(): void
    {
        MockConnector::reset();
        app(Settings::class)->set('erp_mode', 'odoo');
    }

    private function saveBlock(User $user, string $kind, array $overrides = [])
    {
        return $this->actingAs($user)->put("/staff/erp/blocks/{$kind}", [
            'base_url' => "https://{$kind}-erp.example.test", 'database' => "{$kind}-db", 'login' => 'crm-api@example.test',
            'api_key' => self::SECRET_KEY, ...$overrides,
        ]);
    }

    public function test_the_key_is_write_only_and_never_appears_in_responses_logs_or_audit(): void
    {
        $super = $this->super();
        $this->odooMode();
        $logFile = storage_path('logs/laravel-'.now()->format('Y-m-d').'.log');
        $logBefore = is_file($logFile) ? filesize($logFile) : 0;

        $this->saveBlock($super, 'test')->assertRedirect(route('erp.show'));
        $this->actingAs($super)->post('/staff/erp/blocks/test/test')->assertRedirect();

        $page = $this->actingAs($super)->get('/staff/erp')->assertOk();
        $page->assertDontSee(self::SECRET_KEY)->assertSee('Saved (ends …7d6c)');
        $this->actingAs($super)->getJson('/staff/erp')->assertDontSee(self::SECRET_KEY);
        $this->assertStringNotContainsString(self::SECRET_KEY, DB::table('audit_events')->pluck('meta')->implode(' '));
        $this->assertStringNotContainsString(self::SECRET_KEY, (string) DB::table('erp_connections')->value('api_key'), 'Stored encrypted.');
        $this->assertSame(self::SECRET_KEY, ErpConnection::current('test')->apiKey());
        $logs = is_file($logFile) ? substr((string) file_get_contents($logFile), $logBefore) : '';
        $this->assertStringNotContainsString(self::SECRET_KEY, $logs);

        // Leaving the key empty keeps it.
        $this->saveBlock($super, 'test', ['api_key' => '']);
        $this->assertSame(self::SECRET_KEY, ErpConnection::current('test')->apiKey());
    }

    public function test_changing_url_or_database_clears_the_key_and_with_history_starts_a_new_row(): void
    {
        $super = $this->super();
        $this->odooMode();
        $this->saveBlock($super, 'live');
        $first = ErpConnection::current('live');

        // No history yet: same row, key cleared.
        $this->saveBlock($super, 'live', ['database' => 'other-db', 'api_key' => '']);
        $same = ErpConnection::current('live');
        $this->assertSame($first->id, $same->id);
        $this->assertNull($same->apiKey());

        // With history: the row is archived and a new one starts.
        $this->saveBlock($super, 'live', ['database' => 'other-db']);
        DB::table('lead_syncs')->insert(['visitor_id' => Visitor::factory()->create()->id, 'connection_id' => $same->id,
            'ext_ref' => 'EXR-'.Str::ulid(), 'state' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
        $this->saveBlock($super, 'live', ['base_url' => 'https://new-live.example.test', 'api_key' => '']);

        $this->assertNotNull(ErpConnection::query()->find($same->id)->archived_at);
        $new = ErpConnection::current('live');
        $this->assertNotSame($same->id, $new->id);
        $this->assertSame('https://new-live.example.test', $new->base_url);
        $this->assertNull($new->apiKey(), 'The key is not carried over.');
    }

    public function test_urls_must_be_https_without_a_path(): void
    {
        $super = $this->super();
        $this->odooMode();
        foreach (['http://erp.example.test', 'https://erp.example.test/odoo', 'ftp://erp.example.test', 'erp.example.test', 'https://user@erp.example.test'] as $url) {
            $this->saveBlock($super, 'test', ['base_url' => $url])->assertSessionHasErrorsIn('block_test', 'base_url');
        }
        $this->saveBlock($super, 'test', ['base_url' => 'https://ERP.example.test:8443/'])->assertSessionHasNoErrors();
        $this->assertSame('https://erp.example.test:8443', ErpConnection::current('test')->base_url);
    }

    public function test_a_block_with_a_failing_or_stale_test_cannot_be_selected(): void
    {
        $super = $this->super();
        $this->odooMode();
        $this->saveBlock($super, 'test');

        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasErrors('kind');

        MockConnector::script('test', ['rights']);
        $this->actingAs($super)->post('/staff/erp/blocks/test/test')->assertSessionHas('connection_report');
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasErrors('kind');

        $this->actingAs($super)->post('/staff/erp/blocks/test/test');
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasNoErrors();
        $this->assertTrue(ErpConnection::current('test')->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'connection_activated']);

        // A later change makes the test stale again; pushing cannot be switched on.
        $this->travel(1)->minutes();
        $this->saveBlock($super, 'test', ['login' => 'other@example.test']);
        $this->assertFalse(ErpConnection::current('test')->isTested());
        $this->actingAs($super)->put('/staff/erp/push', ['enabled' => '1'])->assertSessionHasErrors('push');
        $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
    }

    public function test_at_most_one_block_is_active_and_switching_waits_for_unfinished_items(): void
    {
        $super = $this->super();
        $live = $this->readyToPush('live');
        $test = $this->testedConnection('test');
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');

        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasErrors('kind');
        $this->saveBlock($super, 'live', ['database' => 'moved-db'])->assertSessionHasErrorsIn('block_live', 'base_url');
        $this->actingAs($super)->get('/staff/erp')->assertSee('Unfinished items on the active connection');

        $this->actingAs($super)->post('/staff/erp/cancel-waiting')->assertRedirect();
        $this->assertSame('cancelled', $this->item($itemId)->state);

        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasNoErrors();
        $this->assertSame(1, ErpConnection::query()->where('is_active', true)->count());
        $this->assertTrue($test->fresh()->is_active);
        $this->assertFalse($live->fresh()->is_active);
    }

    public function test_needs_review_also_blocks_switching_until_resolved(): void
    {
        $super = $this->super();
        $live = $this->readyToPush('live');
        $this->testedConnection('test');
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'needs_review']);

        $this->actingAs($super)->post('/staff/erp/cancel-waiting');
        $this->assertSame('needs_review', $this->item($itemId)->state);
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasErrors('kind');

        $this->actingAs($super)->post("/staff/items/{$itemId}/review", ['decision' => 'lead_exists', 'erp_lead_id' => 55]);
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasNoErrors();
        $this->assertNotNull($live);
    }

    public function test_another_major_version_fails_the_test_and_stops_the_drain(): void
    {
        $super = $this->super();
        $this->readyToPush('live');
        MockConnector::setServerVersion('19.0');

        $this->actingAs($super)->post('/staff/erp/blocks/live/test')
            ->assertSessionHas('connection_report', fn ($r) => ! $r['ok'] && str_contains($r['message'], 'Server runs Odoo 19.0, version selector says 18'));
        $this->assertFalse(ErpConnection::current('live')->last_test_ok);

        $this->queue([Visitor::factory()->create()]);
        $this->assertSame('stopped_version', $this->drain()['outcome']);
    }

    public function test_changing_the_version_selector_marks_both_blocks_untested_and_stops_pushing(): void
    {
        $super = $this->super();
        $this->readyToPush('live');
        $this->testedConnection('test');

        // v1 offers only 18; the rule is exercised through the manager directly.
        app(ConnectionManager::class)->setVersion('19', $super);

        $this->assertFalse(ErpConnection::current('live')->isTested());
        $this->assertFalse(ErpConnection::current('test')->isTested());
        $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
        $this->assertDatabaseHas('audit_events', ['action' => 'odoo_version_changed']);
    }

    public function test_a_visitor_sent_to_test_can_be_sent_to_live_once(): void
    {
        $super = $this->super();
        $test = $this->readyToPush('test');
        $live = $this->testedConnection('live');
        $visitor = Visitor::factory()->create();

        $this->queue([$visitor], connection: $test);
        $this->drain();
        $this->assertSame('succeeded', $this->sync($visitor, $test)->state);

        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'live'])->assertSessionHasNoErrors();
        $this->assertNotNull($this->queue([$visitor], connection: $live));
        $this->drain();
        $this->assertSame('succeeded', $this->sync($visitor, $live)->state);
        $this->assertNotSame($this->sync($visitor, $test)->ext_ref, $this->sync($visitor, $live)->ext_ref);

        $again = app(PushService::class)->createBatch($super, $live, [$visitor->id], (string) Str::ulid());
        $this->assertNull($again['batch']);
        $this->assertSame(2, ErpPushItem::query()->count());
        $this->assertCount(2, MockConnector::leads());
    }

    public function test_the_test_banner_shows_on_every_staff_page_while_test_is_active(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush('test');
        $visitor = Visitor::factory()->create();

        foreach (['/staff', '/staff/visitors', "/staff/visitors/{$visitor->id}", '/staff/entry', '/staff/exhibitions', '/staff/batches', '/staff/password'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('TEST ERP: leads go to https://test-erp.example.test / test-db');
        }

        DB::table('erp_connections')->update(['kind' => 'live']);
        $this->actingAs($admin)->get('/staff')->assertOk()->assertDontSee('TEST ERP');
    }

    public function test_push_cannot_be_switched_on_before_a_passing_test(): void
    {
        $super = $this->super();
        $this->odooMode();
        $this->saveBlock($super, 'live');
        $this->actingAs($super)->post('/staff/erp/blocks/live/test');
        DB::table('erp_connections')->update(['is_active' => true]);

        $this->actingAs($super)->put('/staff/erp/push', ['enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(app(Settings::class)->get('erp_push_enabled'));
        $this->assertDatabaseHas('audit_events', ['action' => 'push_enabled']);

        $this->actingAs($super)->put('/staff/erp/push', ['enabled' => '0']);
        $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
        $this->assertStringContainsString('"reason":"manual"', DB::table('audit_events')->where('action', 'push_disabled')->value('meta'));
    }

    public function test_after_an_app_key_rotation_the_key_must_be_re_entered_and_pushes_fail_as_auth(): void
    {
        $super = $this->super();
        $connection = $this->readyToPush('live');
        $this->queue([Visitor::factory()->create()]);

        // A different key decrypts nothing stored before, as after rotating APP_KEY.
        Model::encryptUsing(new Encrypter(random_bytes(32), 'AES-256-CBC'));
        try {
            $this->actingAs($super)->get('/staff/erp')->assertOk()->assertSee('Key must be re-entered');
            $this->assertSame(['status' => 'unreadable', 'ends' => null], $connection->fresh()->keyStatus());
            $this->assertSame('stopped_auth', $this->drain()['outcome']);
            $this->assertFalse(app(Settings::class)->get('erp_push_enabled'));
        } finally {
            Model::encryptUsing(null);
        }
    }

    public function test_erp_mode_none_is_refused_while_items_are_unfinished(): void
    {
        $super = $this->super();
        $this->readyToPush();
        $this->queue([Visitor::factory()->create()]);
        $base = ['odoo_version' => '18', 'lead_name_template' => '{exhibition}: {visitor_name}'];

        $this->actingAs($super)->put('/staff/erp/settings', [...$base, 'erp_mode' => 'none'])->assertSessionHasErrors('erp_mode');
        $this->assertSame('odoo', app(Settings::class)->get('erp_mode'));

        $this->actingAs($super)->post('/staff/erp/cancel-waiting');
        $this->actingAs($super)->put('/staff/erp/settings', [...$base, 'erp_mode' => 'none'])->assertSessionHasNoErrors();
        $this->assertSame('none', app(Settings::class)->get('erp_mode'));
        $this->assertNull(ErpConnection::active());
    }
}
