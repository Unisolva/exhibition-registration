<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\MockConnector;
use App\Erp\PushService;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * Work order §8 "Push state table": every row (§10.12). Batch creation,
 * claim, transient failure, success, final failure, ambiguous and stale claim
 * are also driven end to end in DrainTest; here each is checked against the
 * table's columns.
 */
class PushStateTableTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function row(int $itemId, Visitor $visitor): array
    {
        $item = $this->item($itemId);
        $sync = $this->sync($visitor, $item->connection);

        return [$item->state, $sync->state, $sync->active_item_id];
    }

    public function test_creation_claim_transient_success_final_ambiguous_and_stale_rows(): void
    {
        $this->readyToPush();
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        $payload = $this->item($itemId)->payload;

        // Batch creation: queued / queued / set to the item.
        $this->assertSame(['queued', 'queued', $itemId], $this->row($itemId, $visitor));

        // Drain claims item: processing / unchanged / unchanged (seen mid-run through a slow create).
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'processing', 'claimed_at' => now()]);
        $this->assertSame(['processing', 'queued', $itemId], $this->row($itemId, $visitor));
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'queued', 'claimed_at' => null]);

        // Transient failure: failed_retry / queued / unchanged; payload unchanged.
        MockConnector::script('create', ['http_5xx']);
        $this->drain();
        $this->assertSame(['failed_retry', 'queued', $itemId], $this->row($itemId, $visitor));
        $this->assertSame($payload, $this->item($itemId)->payload);

        // Stale claim recovered: failed_retry / queued / unchanged.
        DB::table('erp_push_items')->where('id', $itemId)->update(['state' => 'processing', 'claimed_at' => now()->subMinutes(10), 'next_attempt_at' => now()->addHour()]);
        MockConnector::script('search', ['network']);
        $this->drain();
        $this->assertSame(['failed_retry', 'queued', $itemId], $this->row($itemId, $visitor));
        $this->assertSame('network', $this->item($itemId)->error_class);

        // Success: succeeded / succeeded / cleared.
        $this->makeDue($itemId);
        $this->drain();
        $this->assertSame(['succeeded', 'succeeded', null], $this->row($itemId, $visitor));

        // Final failure: failed_final / failed / cleared.
        $other = Visitor::factory()->create();
        $finalId = $this->queue([$other])->items()->value('id');
        MockConnector::script('create', ['unknown']);
        $this->drain();
        $this->assertSame(['failed_final', 'failed', null], $this->row($finalId, $other));

        // Ambiguous: needs_review / needs_review / unchanged (still locked).
        $third = Visitor::factory()->create();
        $ambiguousId = $this->queue([$third])->items()->value('id');
        $ref = $this->sync($third, $this->item($ambiguousId)->connection)->ext_ref;
        MockConnector::seedLead("Ref: {$ref}");
        MockConnector::seedLead("Ref: {$ref} (copy)");
        $this->drain();
        $this->assertSame(['needs_review', 'needs_review', $ambiguousId], $this->row($ambiguousId, $third));
    }

    public function test_cancel_from_queued_or_failed_retry_only(): void
    {
        $admin = User::factory()->admin()->create();
        $connection = $this->readyToPush();
        [$a, $b] = Visitor::factory()->count(2)->create()->all();

        // A visitor without earlier failures goes back to never.
        $itemA = $this->queue([$a])->items()->value('id');
        $this->actingAs($admin)->post("/staff/batches/{$this->item($itemA)->batch_id}/cancel")->assertRedirect();
        $this->assertSame(['cancelled', 'never', null], $this->row($itemA, $a));

        // A visitor with an earlier failed_final item on the same connection goes back to failed.
        $firstB = $this->queue([$b])->items()->value('id');
        MockConnector::script('create', ['validation']);
        $this->drain();
        $this->assertSame(['failed_final', 'failed', null], $this->row($firstB, $b));
        $secondB = $this->queue([$b])->items()->value('id');
        MockConnector::script('create', ['http_5xx']);
        $this->drain();
        $this->assertSame('failed_retry', $this->item($secondB)->state);
        $this->assertSame(1, app(PushService::class)->cancel([$secondB], $admin));
        $this->assertSame(['cancelled', 'failed', null], $this->row($secondB, $b));

        // Nothing else can be cancelled.
        $this->assertSame(0, app(PushService::class)->cancel([$firstB, $secondB], $admin));
        $this->assertSame('failed_final', $this->item($firstB)->state);
        $this->assertDatabaseHas('audit_events', ['action' => 'items_cancelled']);
        $this->assertNotNull($connection);
    }

    public function test_retry_failed_requeues_the_same_item_with_a_rebuilt_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();
        $visitor = Visitor::factory()->create(['name' => 'Old Name', 'region_code' => 'C']);
        $itemId = $this->queue([$visitor])->items()->value('id');
        MockConnector::script('create', ['validation']);
        $this->drain();
        $this->assertSame(['failed_final', 'failed', null], $this->row($itemId, $visitor));

        // Unlocked now, so it can be corrected before the retry.
        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'Corrected Name', 'phone' => $visitor->phone_raw, 'governorate' => 'C'])->assertRedirect();
        $this->actingAs($admin)->get("/staff/batches/{$this->item($itemId)->batch_id}")->assertOk()->assertSee('Retry');
        $this->actingAs($admin)->post("/staff/items/{$itemId}/retry")->assertRedirect();

        $item = $this->item($itemId);
        $this->assertSame(['queued', 'queued', $itemId], $this->row($itemId, $visitor));
        $this->assertSame(0, $item->attempts);
        $this->assertSame('Corrected Name', $item->payload['contact_name']);
        $this->assertDatabaseHas('audit_events', ['action' => 'item_retried', 'target_id' => $itemId]);

        $this->drain();
        $this->assertSame('succeeded', $this->item($itemId)->state);
    }

    public function test_retry_is_refused_unless_every_condition_holds(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush();

        // Not failed_final.
        $visitor = Visitor::factory()->create();
        $itemId = $this->queue([$visitor])->items()->value('id');
        $this->actingAs($admin)->post("/staff/items/{$itemId}/retry")->assertStatus(409);

        // Visitor trashed.
        MockConnector::script('create', ['validation']);
        $this->drain();
        DB::table('visitors')->where('id', $visitor->id)->update(['trashed_at' => now()]);
        $this->actingAs($admin)->post("/staff/items/{$itemId}/retry")->assertStatus(409);
        DB::table('visitors')->where('id', $visitor->id)->update(['trashed_at' => null]);

        // Another item is active for the visitor.
        $newer = $this->queue([$visitor])->items()->value('id');
        $this->actingAs($admin)->post("/staff/items/{$itemId}/retry")->assertStatus(409);
        app(PushService::class)->cancel([$newer], $admin);

        // The item's connection is not the active one any more.
        $test = $this->testedConnection('test');
        DB::table('erp_connections')->update(['is_active' => false]);
        $test->forceFill(['is_active' => true])->save();
        $this->actingAs($admin)->post("/staff/items/{$itemId}/retry")->assertStatus(409);

        $this->assertSame('failed_final', $this->item($itemId)->state);
    }

    public function test_mark_reviewed_lead_exists_and_send_again(): void
    {
        $admin = User::factory()->admin()->create();
        $connection = $this->readyToPush();
        [$a, $b] = Visitor::factory()->count(2)->create()->all();
        $batch = $this->queue([$a, $b]);
        [$itemA, $itemB] = $batch->items()->orderBy('id')->pluck('id')->all();
        foreach ([$a, $b] as $visitor) {
            $ref = $this->sync($visitor, $connection)->ext_ref;
            MockConnector::seedLead("Ref: {$ref}");
            MockConnector::seedLead("Ref: {$ref} duplicate");
        }
        $this->drain();
        $this->assertSame('needs_review', $this->item($itemA)->state);

        // Lead exists: succeeded / succeeded / cleared, with the entered lead ID.
        $this->actingAs($admin)->post("/staff/items/{$itemA}/review", ['decision' => 'lead_exists', 'erp_lead_id' => 4321])->assertRedirect();
        $this->assertSame(['succeeded', 'succeeded', null], $this->row($itemA, $a));
        $this->assertSame(4321, $this->item($itemA)->erp_lead_id);
        $this->assertSame(4321, (int) $this->sync($a, $connection)->erp_lead_id);

        // Send again: queued / queued / unchanged, attempts reset, payload unchanged.
        $payloadB = $this->item($itemB)->payload;
        $this->actingAs($admin)->post("/staff/items/{$itemB}/review", ['decision' => 'send_again'])->assertRedirect();
        $this->assertSame(['queued', 'queued', $itemB], $this->row($itemB, $b));
        $this->assertSame(0, $this->item($itemB)->attempts);
        $this->assertSame($payloadB, $this->item($itemB)->payload);

        // Only from needs_review.
        $this->actingAs($admin)->post("/staff/items/{$itemA}/review", ['decision' => 'send_again'])->assertStatus(409);
        $this->actingAs($admin)->post("/staff/items/{$itemB}/review", ['decision' => 'lead_exists'])->assertSessionHasErrors('erp_lead_id');
        $this->assertSame(2, DB::table('audit_events')->where('action', 'item_marked_reviewed')->count());
    }

    public function test_the_batch_screen_shows_destination_states_errors_and_lead_links(): void
    {
        $admin = User::factory()->admin()->create();
        $this->readyToPush('test');
        $batch = $this->queue(Visitor::factory()->count(2)->create()->all());
        MockConnector::script('create', ['ok', 'validation']);
        $this->drain();
        $leadId = $batch->items()->where('state', 'succeeded')->value('erp_lead_id');

        $this->actingAs($admin)->get("/staff/batches/{$batch->id}")
            ->assertOk()
            ->assertSee('TEST')
            ->assertSee('https://test-erp.example.test/web#id='.$leadId.'&amp;model=crm.lead&amp;view_type=form', false)
            ->assertSee('validation');

        $this->actingAs($admin)->getJson("/staff/batches/{$batch->id}/status")
            ->assertOk()->assertJson(['totals' => ['succeeded' => 1, 'failed_final' => 1], 'active' => false]);
    }
}
