<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErpRows;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * Work order §4 trash (D12) and permanent deletion, §8 housekeeping, §10.11
 * (trash part) and §10.16.
 */
class TrashAndHousekeepingTest extends TestCase
{
    use ErpRows;
    use PublicForm;
    use RefreshDatabase;

    public function test_trashed_visitors_leave_lists_exports_and_duplicate_matching_and_restore_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create();
        $first = Visitor::factory()->at($exhibition)->create(['name' => 'Trash Candidate', 'phone_raw' => '01001112222', 'phone_key' => '+201001112222', 'created_at' => now()->subHour()]);
        $later = Visitor::factory()->at($exhibition)->create(['phone_key' => '+201001112222', 'possible_duplicate_of' => $first->id]);
        $before = $first->fresh()->getAttributes();

        $this->actingAs($admin)->delete("/staff/visitors/{$first->id}")->assertRedirect(route('visitors.index'));
        $this->assertNotNull($first->fresh()->trashed_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor_trashed', 'target_id' => $first->id]);

        $this->actingAs($admin)->get('/staff/visitors')->assertOk()->assertDontSee('Trash Candidate');
        $this->assertStringNotContainsString('Trash Candidate', $this->actingAs($admin)->get('/staff/visitors/export')->streamedContent());
        $this->actingAs($admin)->get('/staff/trash')->assertOk()->assertSee('Trash Candidate')->assertSee('30');

        // The later visitor no longer points at it, and a new registration is not matched to it.
        $this->assertNull($later->fresh()->possible_duplicate_of);
        $this->submitPublicForm($exhibition, ['name' => 'New', 'phone' => '01001112222', 'governorate' => 'C'])->assertStatus(303);
        $this->assertSame($later->id, Visitor::query()->where('name', 'New')->sole()->possible_duplicate_of);

        // Edits are refused while it is in the trash.
        $this->actingAs($admin)->put("/staff/visitors/{$first->id}", ['name' => 'X', 'phone' => '01001112222'])->assertNotFound();

        $this->actingAs($admin)->post("/staff/trash/{$first->id}/restore")->assertRedirect(route('trash.index'));
        $restored = $first->fresh()->getAttributes();
        foreach (['name', 'phone_raw', 'phone_key', 'email', 'region_code', 'extra', 'payload_hash', 'submission_token', 'created_at'] as $column) {
            $this->assertSame($before[$column], $restored[$column], $column);
        }
        $this->assertNull($restored['trashed_at']);
        $this->assertSame($first->id, $later->fresh()->possible_duplicate_of);
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor_restored', 'target_id' => $first->id]);
    }

    public function test_only_a_super_admin_deletes_permanently_with_a_typed_confirmation(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();
        $visitor = Visitor::factory()->trashed($admin)->create(['name' => 'Gone Soon', 'email' => 'gone@example.test', 'extra' => ['farm' => 'x']]);
        DB::table('visitor_edits')->insert(['visitor_id' => $visitor->id, 'user_id' => $admin->id, 'at' => now(), 'changes' => '{"name":["A","Gone Soon"]}']);
        $connection = $this->erpConnection('test');
        $this->syncState($visitor, $connection, 'failed');
        $itemId = $this->activeItem(Visitor::factory()->create(), $connection);
        DB::table('erp_push_items')->where('id', $itemId)->update(['visitor_id' => $visitor->id, 'state' => 'failed_final']);

        $this->actingAs($admin)->delete("/staff/trash/{$visitor->id}", ['confirm' => 'DELETE'])->assertForbidden();
        $this->actingAs($super)->delete("/staff/trash/{$visitor->id}", ['confirm' => 'delete'])->assertSessionHasErrors('confirm', null, 'delete'.$visitor->id);
        $this->assertNull($visitor->fresh()->deleted_at);

        $this->actingAs($super)->delete("/staff/trash/{$visitor->id}", ['confirm' => 'DELETE'])->assertRedirect(route('trash.index'));

        $row = $visitor->fresh();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame($super->id, $row->deleted_by);
        $this->assertSame('', $row->name);
        $this->assertSame('', $row->phone_raw);
        $this->assertSame('', $row->phone_key);
        $this->assertNull($row->email);
        $this->assertNull($row->extra);
        $this->assertSame(0, DB::table('visitor_edits')->where('visitor_id', $visitor->id)->count());
        $this->assertSame('{}', DB::table('erp_push_items')->where('id', $itemId)->value('payload'));
        // Batch and sync history stay readable.
        $this->assertSame(1, DB::table('lead_syncs')->where('visitor_id', $visitor->id)->count());
        $this->assertSame(1, DB::table('erp_batches')->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor_deleted', 'target_id' => $visitor->id, 'user_id' => $super->id]);
        $this->actingAs($super)->get('/staff/trash')->assertOk()->assertDontSee('Gone Soon');
    }

    public function test_housekeeping_empties_the_trash_after_30_days_only(): void
    {
        $old = Visitor::factory()->trashed(daysAgo: 31)->create(['name' => 'Thirty One Days']);
        $recent = Visitor::factory()->trashed(daysAgo: 29)->create(['name' => 'Twenty Nine Days']);

        $this->artisan('housekeeping:run')->assertSuccessful();

        $this->assertNotNull($old->fresh()->deleted_at);
        $this->assertNull($old->fresh()->deleted_by);
        $this->assertSame('', $old->fresh()->name);
        $this->assertNull($recent->fresh()->deleted_at);
        $this->assertSame('Twenty Nine Days', $recent->fresh()->name);
    }

    public function test_retention_deletes_only_old_records_ignores_the_live_lock_and_skips_active_items(): void
    {
        app(Settings::class)->set('retention_months', 6);
        $live = $this->erpConnection('live');

        $young = Visitor::factory()->create(['created_at' => now()->subMonths(5)]);
        $old = Visitor::factory()->create(['created_at' => now()->subMonths(7)]);
        $oldSentToLive = Visitor::factory()->create(['created_at' => now()->subMonths(8)]);
        $this->syncState($oldSentToLive, $live, 'succeeded', 9001);
        $oldInFlight = Visitor::factory()->create(['created_at' => now()->subMonths(9)]);
        $this->activeItem($oldInFlight, $live);

        $this->artisan('housekeeping:run')->assertSuccessful();

        $this->assertNull($young->fresh()->deleted_at);
        $this->assertNotNull($old->fresh()->deleted_at);
        $this->assertNotNull($oldSentToLive->fresh()->deleted_at);
        $this->assertNull($oldInFlight->fresh()->deleted_at);
        $this->assertSame(9001, (int) DB::table('lead_syncs')->where('visitor_id', $oldSentToLive->id)->value('erp_lead_id'));
    }

    public function test_housekeeping_removes_old_drain_runs_and_expired_sessions(): void
    {
        DB::table('drain_runs')->insert([
            ['started_at' => now()->subDays(31), 'items_processed' => 0, 'outcome' => 'ok'],
            ['started_at' => now()->subDays(2), 'items_processed' => 0, 'outcome' => 'ok'],
        ]);
        DB::table('sessions')->insert([
            ['id' => 'expired-session', 'payload' => '', 'last_activity' => now()->subHours(13)->getTimestamp()],
            ['id' => 'live-session', 'payload' => '', 'last_activity' => now()->subHour()->getTimestamp()],
        ]);

        $this->artisan('housekeeping:run')->assertSuccessful();

        $this->assertSame(1, DB::table('drain_runs')->count());
        $this->assertSame(['live-session'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_a_locked_visitor_cannot_be_trashed(): void
    {
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create();
        $this->activeItem($visitor, $this->erpConnection('live'));

        $this->actingAs($admin)->delete("/staff/visitors/{$visitor->id}")->assertStatus(423);
        $this->assertNull($visitor->fresh()->trashed_at);
    }
}
