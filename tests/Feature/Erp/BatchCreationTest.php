<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Models\ErpBatch;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §8 batch creation and §10.8.
 */
class BatchCreationTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    public function test_a_batch_holds_exactly_the_posted_eligible_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $connection = $this->readyToPush();
        $selected = Visitor::factory()->count(3)->create();
        $unselected = Visitor::factory()->create();

        $this->actingAs($admin)->post('/staff/batches', [
            'batch_token' => (string) Str::ulid(),
            'visitor_ids' => [$selected[0]->id, $selected[1]->id, $selected[2]->id, $selected[0]->id],
        ])->assertRedirect();

        $batch = ErpBatch::query()->sole();
        $this->assertSame(3, $batch->item_count);
        $this->assertSame($connection->id, $batch->connection_id);
        $this->assertEqualsCanonicalizing($selected->pluck('id')->all(), ErpPushItem::query()->pluck('visitor_id')->all());
        $this->assertNull($this->sync($unselected, $connection));
        foreach ($selected as $visitor) {
            $sync = $this->sync($visitor, $connection);
            $this->assertSame('queued', $sync->state);
            $this->assertNotNull($sync->active_item_id);
            $this->assertMatchesRegularExpression('/^EXR-[0-9A-Z]{26}$/', $sync->ext_ref);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'batch_created', 'user_id' => $admin->id]);
    }

    public function test_succeeded_queued_and_trashed_visitors_create_no_item(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        [$sent, $queued, $fresh] = Visitor::factory()->count(3)->create()->all();
        $trashed = Visitor::factory()->trashed()->create();

        $this->queue([$sent]);
        $this->drain();
        $this->queue([$queued]);
        $this->assertSame(2, ErpPushItem::query()->count());

        $this->actingAs($admin)->post('/staff/batches', [
            'batch_token' => (string) Str::ulid(),
            'visitor_ids' => [$sent->id, $queued->id, $fresh->id, $trashed->id],
        ])->assertRedirect()->assertSessionHas('status', '1 visitor queued, 3 skipped.');

        $this->assertSame(3, ErpPushItem::query()->count());
        $this->assertSame(1, ErpPushItem::query()->where('visitor_id', $fresh->id)->count());
        $this->assertSame(0, ErpPushItem::query()->where('visitor_id', $trashed->id)->count());
    }

    public function test_replaying_a_batch_token_creates_no_second_batch(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        $visitors = Visitor::factory()->count(2)->create();
        $body = ['batch_token' => (string) Str::ulid(), 'visitor_ids' => $visitors->pluck('id')->all()];

        $first = $this->actingAs($admin)->post('/staff/batches', $body)->assertRedirect()->headers->get('Location');
        $second = $this->actingAs($admin)->post('/staff/batches', $body)->assertRedirect()->headers->get('Location');

        $this->assertSame($first, $second);
        $this->assertSame(1, ErpBatch::query()->count());
        $this->assertSame(2, ErpPushItem::query()->count());
    }

    public function test_the_payload_is_built_from_the_stored_record_never_from_the_request(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        $visitor = Visitor::factory()->create(['name' => 'Stored Name']);

        $this->actingAs($admin)->post('/staff/batches', [
            'batch_token' => (string) Str::ulid(),
            'visitor_ids' => [$visitor->id],
            'name' => 'Injected Name', 'payload' => ['name' => 'Injected'], 'contact_name' => 'Injected',
        ])->assertRedirect();

        $payload = ErpPushItem::query()->sole()->payload;
        $this->assertSame('Stored Name', $payload['contact_name']);
        $this->assertStringNotContainsString('Injected', json_encode($payload));
    }

    public function test_more_than_200_ids_are_refused(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();

        $this->actingAs($admin)->post('/staff/batches', ['batch_token' => (string) Str::ulid(), 'visitor_ids' => range(1, 201)])
            ->assertSessionHasErrors('visitor_ids');
        $this->assertSame(0, ErpBatch::query()->count());
    }

    public function test_the_confirm_screen_names_the_destination_and_counts_skips(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush('test');
        [$a, $b] = Visitor::factory()->count(2)->create()->all();
        $this->queue([$a]);

        $this->actingAs($admin)->post('/staff/batches/preview', ['visitor_ids' => [$a->id, $b->id]])
            ->assertOk()
            ->assertSee('TEST')
            ->assertSee('https://test-erp.example.test / test-db')
            ->assertSee('1 will be skipped')
            ->assertSee('Send 1 lead to TEST');
    }

    public function test_the_list_offers_sending_only_to_managers_in_odoo_mode(): void
    {
        // M10 CO-8: managers always select rows (for "Move selected to trash"); sending needs Odoo mode.
        Visitor::factory()->create();
        $this->actingAs(User::factory()->admin()->create())->get('/staff/visitors')->assertOk()
            ->assertSee('data-select-row', false)->assertDontSee('Send selected to Odoo');

        $this->readyToPush();
        $this->actingAs(User::factory()->admin()->create())->get('/staff/visitors')->assertOk()->assertSee('data-select-row', false)->assertSee('Select all on this page')
            ->assertSee('Send selected to Odoo');

        $staff = User::factory()->staff()->create();
        Visitor::factory()->enteredBy($staff)->create();
        $this->actingAs($staff)->get('/staff/visitors')->assertOk()->assertDontSee('data-select-row', false);
    }
}
