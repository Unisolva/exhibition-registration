<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use App\Visitors\VisitorLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ErpRows;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * Work order §4 record lock, §7 "Edit and delete" and §10.11 (edit log, lock;
 * the edit/batch race follows with the push engine in M4).
 */
class EditAndLockTest extends TestCase
{
    use ErpRows;
    use PublicForm;
    use RefreshDatabase;

    public function test_an_edit_writes_one_log_row_with_only_the_changed_fields(): void
    {
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create(['name' => 'Old Name', 'email' => null, 'region_code' => 'C', 'region_label' => 'Cairo']);

        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", [
            'name' => 'New Name', 'phone' => $visitor->phone_raw, 'email' => '', 'governorate' => 'ALX',
        ])->assertRedirect(route('visitors.show', $visitor));

        $edit = DB::table('visitor_edits')->where('visitor_id', $visitor->id)->sole();
        $this->assertSame(['name' => ['Old Name', 'New Name'], 'governorate' => ['C', 'ALX']], json_decode($edit->changes, true));
        $this->assertSame($admin->id, $edit->user_id);

        $visitor->refresh();
        $this->assertNotNull($visitor->edited_at);
        $this->assertSame('Alexandria', $visitor->region_label);
        $this->assertDatabaseHas('audit_events', ['action' => 'visitor_edited', 'target_id' => $visitor->id]);

        // Saving again without changes writes nothing.
        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'New Name', 'phone' => $visitor->phone_raw, 'governorate' => 'ALX']);
        $this->assertSame(1, DB::table('visitor_edits')->where('visitor_id', $visitor->id)->count());

        $this->actingAs($admin)->get("/staff/visitors/{$visitor->id}")->assertOk()->assertSee('Old Name')->assertSee('New Name');
    }

    public function test_editing_keeps_the_payload_hash_and_a_replay_of_the_original_still_succeeds(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);
        $original = ['name' => 'Original Visitor', 'phone' => '01002223333', 'governorate' => 'C'];
        $this->submitPublicForm($exhibition, $original, $hidden)->assertStatus(303);
        $visitor = Visitor::query()->sole();
        $hash = $visitor->payload_hash;

        $this->actingAs(User::factory()->admin()->create())
            ->put("/staff/visitors/{$visitor->id}", [...$original, 'name' => 'Corrected Visitor'])->assertRedirect();
        $this->assertSame($hash, $visitor->fresh()->payload_hash);
        $this->assertSame('Corrected Visitor', $visitor->fresh()->name);

        $this->newProcess();
        $this->submitPublicForm($exhibition, $original, $hidden)->assertStatus(303)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_validation_errors_keep_the_record_unchanged(): void
    {
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create(['name' => 'Stays']);

        $this->actingAs($admin)->from("/staff/visitors/{$visitor->id}/edit")
            ->put("/staff/visitors/{$visitor->id}", ['name' => '', 'phone' => '12'])
            ->assertRedirect("/staff/visitors/{$visitor->id}/edit")
            ->assertSessionHasErrors(['name', 'phone']);
        $this->assertSame('Stays', $visitor->fresh()->name);
        $this->assertSame(0, DB::table('visitor_edits')->count());
    }

    public function test_stored_values_of_inactive_fields_survive_an_edit(): void
    {
        $admin = User::factory()->admin()->create();
        FieldDefinition::query()->create([
            'key' => 'farm_size', 'is_system' => false, 'type' => 'text', 'label_en' => 'Farm size', 'label_ar' => 'مساحة',
            'is_active' => false, 'sort_order' => 90, 'send_to_erp' => true,
        ]);
        FieldDefinition::query()->where('key', 'email')->update(['is_active' => false]);
        $visitor = Visitor::factory()->create(['email' => 'kept@example.test', 'extra' => ['farm_size' => '12']]);

        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'Renamed', 'phone' => $visitor->phone_raw, 'governorate' => 'C']);

        $visitor->refresh();
        $this->assertSame('Renamed', $visitor->name);
        $this->assertSame('kept@example.test', $visitor->email);
        $this->assertSame(['farm_size' => '12'], $visitor->extra);
    }

    public function test_the_duplicate_flag_is_recomputed_after_a_phone_edit(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create();
        $first = Visitor::factory()->at($exhibition)->create(['created_at' => now()->subMinutes(5)]);
        $second = Visitor::factory()->at($exhibition)->create();

        $this->actingAs($admin)->put("/staff/visitors/{$second->id}", ['name' => $second->name, 'phone' => $first->phone_raw, 'governorate' => 'C']);
        $this->assertSame($first->id, $second->fresh()->possible_duplicate_of);

        $this->actingAs($admin)->put("/staff/visitors/{$second->id}", ['name' => $second->name, 'phone' => '01009998888', 'governorate' => 'C']);
        $this->assertNull($second->fresh()->possible_duplicate_of);
    }

    public function test_after_a_live_success_edit_and_trash_are_423_for_every_role(): void
    {
        $staff = User::factory()->staff()->create();
        $visitor = Visitor::factory()->enteredBy($staff)->create(['name' => 'Sent To Live']);
        $this->syncState($visitor, $this->erpConnection('live'), 'succeeded', 501);

        $actors = [User::factory()->superAdmin()->create(), User::factory()->admin()->create(), $staff];
        foreach ($actors as $actor) {
            $this->actingAs($actor)->put("/staff/visitors/{$visitor->id}", ['name' => 'Changed', 'phone' => $visitor->phone_raw])
                ->assertStatus(423)->assertSee('Already sent to the live ERP');
            $this->actingAs($actor)->putJson("/staff/visitors/{$visitor->id}", ['name' => 'Changed', 'phone' => $visitor->phone_raw])
                ->assertStatus(423)->assertJson(['cause' => VisitorLock::LIVE, 'message' => 'Already sent to the live ERP']);
        }
        foreach (array_slice($actors, 0, 2) as $actor) {
            $this->actingAs($actor)->delete("/staff/visitors/{$visitor->id}")->assertStatus(423);
        }

        $this->assertSame('Sent To Live', $visitor->fresh()->name);
        $this->assertNull($visitor->fresh()->trashed_at);
        $this->actingAs($actors[0])->get('/staff/visitors')->assertOk()->assertSee('Locked');
    }

    public function test_a_test_only_success_does_not_lock(): void
    {
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create();
        $this->syncState($visitor, $this->erpConnection('test', active: true), 'succeeded', 77);

        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'Editable', 'phone' => $visitor->phone_raw, 'governorate' => 'C'])
            ->assertRedirect();
        $this->assertSame('Editable', $visitor->fresh()->name);
    }

    public function test_an_active_item_locks_until_it_is_finished(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['queued', 'processing', 'failed_retry', 'needs_review'] as $state) {
            $visitor = Visitor::factory()->create();
            $itemId = $this->activeItem($visitor, $this->erpConnection('test'), $state);

            $this->actingAs($admin)->putJson("/staff/visitors/{$visitor->id}", ['name' => 'X', 'phone' => $visitor->phone_raw])
                ->assertStatus(423)->assertJson(['message' => 'Being sent to the ERP; try again when the push finishes']);
            $this->actingAs($admin)->deleteJson("/staff/visitors/{$visitor->id}")->assertStatus(423);

            // The item finishes: active_item_id is cleared and the visitor is editable again.
            DB::table('lead_syncs')->where('active_item_id', $itemId)->update(['active_item_id' => null, 'state' => 'failed']);
            $this->actingAs($admin)->putJson("/staff/visitors/{$visitor->id}", ['name' => 'Free Again', 'phone' => $visitor->phone_raw, 'governorate' => 'C'])
                ->assertOk();
        }
    }
}
