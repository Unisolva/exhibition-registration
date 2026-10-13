<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work order §10.6: staff cannot read, edit or list another user's entries or
 * any public entry by ID, and DELETE is 403 even for their own entries.
 */
class VisitorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_list_only_their_own_entries(): void
    {
        $exhibition = Exhibition::factory()->create();
        $me = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $mine = Visitor::factory()->at($exhibition)->enteredBy($me)->create(['name' => 'Mine Person']);
        Visitor::factory()->at($exhibition)->enteredBy($other)->create(['name' => 'Other Person']);
        Visitor::factory()->at($exhibition)->create(['name' => 'Public Person']);

        $this->actingAs($me)->get('/staff/visitors')
            ->assertOk()->assertSee('Mine Person')->assertDontSee('Other Person')->assertDontSee('Public Person');

        // Filters cannot widen it.
        $this->actingAs($me)->get('/staff/visitors?source=public&exhibition='.$exhibition->id)
            ->assertDontSee('Public Person')->assertDontSee('Other Person');

        $this->actingAs(User::factory()->admin()->create())->get('/staff/visitors')
            ->assertSee('Mine Person')->assertSee('Other Person')->assertSee('Public Person');

        $this->assertNotNull($mine);
    }

    public function test_staff_cannot_open_edit_or_change_other_entries_by_id(): void
    {
        $me = User::factory()->staff()->create();
        $others = [
            Visitor::factory()->enteredBy(User::factory()->staff()->create())->create(),
            Visitor::factory()->create(),
        ];

        foreach ($others as $visitor) {
            $this->actingAs($me)->get("/staff/visitors/{$visitor->id}")->assertForbidden();
            $this->actingAs($me)->get("/staff/visitors/{$visitor->id}/edit")->assertForbidden();
            $this->actingAs($me)->put("/staff/visitors/{$visitor->id}", ['name' => 'Hijacked', 'phone' => '01001234567'])->assertForbidden();
            $this->assertNotSame('Hijacked', $visitor->fresh()->name);
        }
    }

    public function test_staff_can_open_and_edit_their_own_entry_but_never_delete_it(): void
    {
        $me = User::factory()->staff()->create();
        $mine = Visitor::factory()->enteredBy($me)->create();

        $this->actingAs($me)->get("/staff/visitors/{$mine->id}")->assertOk();
        $this->actingAs($me)->get("/staff/visitors/{$mine->id}/edit")->assertOk();
        $this->actingAs($me)->get("/staff/visitors/{$mine->id}/delete")->assertForbidden();
        $this->actingAs($me)->delete("/staff/visitors/{$mine->id}")->assertForbidden();
        $this->actingAs($me)->deleteJson("/staff/visitors/{$mine->id}")->assertForbidden();
        $this->assertNull($mine->fresh()->trashed_at);
    }

    public function test_staff_see_the_duplicate_flag_but_never_the_matching_record(): void
    {
        $exhibition = Exhibition::factory()->create();
        $me = User::factory()->staff()->create();
        $first = Visitor::factory()->at($exhibition)->create(['name' => 'Earlier Public Visitor', 'phone_key' => '+201001110000']);
        $mine = Visitor::factory()->at($exhibition)->enteredBy($me)->create(['phone_key' => '+201001110000', 'possible_duplicate_of' => $first->id]);

        $this->actingAs($me)->get("/staff/visitors/{$mine->id}")
            ->assertOk()->assertSee('Possible duplicate')
            ->assertDontSee('Earlier Public Visitor')
            ->assertDontSee("/staff/visitors/{$first->id}\"", false);

        $this->actingAs(User::factory()->admin()->create())->get("/staff/visitors/{$mine->id}")
            ->assertSee("/staff/visitors/{$first->id}", false);
    }

    public function test_trashed_and_deleted_visitors_are_not_reachable_by_id(): void
    {
        $admin = User::factory()->admin()->create();
        $trashed = Visitor::factory()->trashed($admin)->create();
        $deleted = Visitor::factory()->create(['deleted_at' => now(), 'name' => '']);

        foreach ([$trashed, $deleted] as $visitor) {
            $this->actingAs($admin)->get("/staff/visitors/{$visitor->id}")->assertNotFound();
            $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'X', 'phone' => '01001234567'])->assertNotFound();
        }
    }
}
