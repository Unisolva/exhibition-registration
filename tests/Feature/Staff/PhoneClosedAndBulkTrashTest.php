<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Http\Controllers\Staff\EntryController;
use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Registration\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PublicForm;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * M10 CO-6 (phone numbers must be valid), CO-7 (no paper forms: closed
 * exhibitions take no staff entries) and CO-8 (bulk "Move to trash").
 */
class PhoneClosedAndBulkTrashTest extends TestCase
{
    use PublicForm;
    use PushFixtures;
    use RefreshDatabase;

    private function entry(Exhibition $exhibition, array $values, ?string $token = null): array
    {
        return [...$values, 'exhibition_id' => (string) $exhibition->id, 'submission_token' => $token ?? (string) Str::ulid()];
    }

    public function test_co6_an_invalid_number_is_refused_on_the_public_form_save_and_new_and_edit(): void
    {
        $arabic = Exhibition::factory()->create(['default_locale' => 'ar']);
        $english = Exhibition::factory()->english()->create();

        // Public, in both languages; a valid mobile still passes.
        $this->submitPublicForm($arabic, ['name' => 'زائر', 'phone' => '0123456789'])->assertStatus(422)->assertSee('رقم الهاتف غير صحيح. برجاء التأكد منه.');
        $this->submitPublicForm($english, ['name' => 'Visitor', 'phone' => '0123456789'])->assertStatus(422)
            ->assertSee(e("This phone number doesn't look right. Please check it."), false);
        $this->submitPublicForm($english, ['name' => 'Visitor', 'phone' => '01001234567'])->assertRedirect();

        // Save & New: the staff wording.
        $staff = User::factory()->create();
        $this->actingAs($staff)->postJson('/staff/registrations', $this->entry($english, ['name' => 'Desk', 'phone' => '0123456789']))
            ->assertStatus(422)->assertJsonPath('errors.phone', Normalizer::STAFF_PHONE_INVALID);
        $this->actingAs($staff)->postJson('/staff/registrations', $this->entry($english, ['name' => 'Desk', 'phone' => '+20 100 765 4321']))->assertCreated();

        // An existing visitor saved with an unrecognised number is not touched; editing applies the rule.
        $old = Visitor::factory()->create(['phone_raw' => '0123456789', 'phone_valid' => false, 'phone_e164' => null]);
        $this->assertFalse($old->fresh()->phone_valid);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put("/staff/visitors/{$old->id}", ['name' => $old->name, 'phone' => '0123456789'])->assertSessionHasErrors('phone');
        $this->actingAs($admin)->put("/staff/visitors/{$old->id}", ['name' => $old->name, 'phone' => '01001112222'])->assertSessionHasNoErrors();
        $this->assertTrue($old->fresh()->phone_valid);
    }

    public function test_co7_save_and_new_lists_open_exhibitions_only_and_refuses_closed_ones_but_replays_still_succeed(): void
    {
        $open = Exhibition::factory()->create(['name' => 'Open Fair']);
        $closed = Exhibition::factory()->closed()->create(['name' => 'Closed Fair']);
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/staff/entry?change=1')->assertOk()->assertSee('Open Fair')->assertDontSee('Closed Fair')
            ->assertDontSee('paper forms');
        $this->actingAs($staff)->get("/staff/entry?exhibition={$closed->id}")->assertOk()
            ->assertSee('Registration for Closed Fair is closed.')->assertDontSee('data-entry', false);

        // A committed entry, then the exhibition closes: its replay still succeeds, a new entry is refused.
        $token = (string) Str::ulid();
        $values = $this->entry($open, ['name' => 'Desk Visitor', 'phone' => '01001234567'], $token);
        $this->actingAs($staff)->postJson('/staff/registrations', $values)->assertCreated();
        $open->forceFill(['is_open' => false])->save();
        $this->actingAs($staff)->postJson('/staff/registrations', $values)->assertOk()->assertJsonPath('status', 'replay');
        $this->actingAs($staff)->postJson('/staff/registrations', $this->entry($open, ['name' => 'Late', 'phone' => '01002223333']))
            ->assertStatus(422)->assertJsonPath('errors._form', EntryController::CLOSED_NOTE);
        $this->assertSame(1, Visitor::query()->count());

        // The session no longer opens a closed exhibition.
        $this->actingAs($staff)->get('/staff/entry')->assertOk()->assertDontSee('data-entry', false);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get("/staff/exhibitions/{$open->id}")->assertDontSee('paper forms');
        $this->actingAs($admin)->get("/staff/exhibitions/{$closed->id}/close")->assertDontSee('Staff can still enter visitors');
    }

    public function test_co8_managers_move_selected_visitors_to_the_trash_skipping_locked_ones(): void
    {
        $live = $this->readyToPush('live');
        $admin = User::factory()->admin()->create();
        [$a, $b, $locked] = Visitor::factory()->count(3)->sequence(['name' => 'Alpha One'], ['name' => 'Beta Two'], ['name' => 'Gamma Locked'])->create();
        DB::table('lead_syncs')->insert(['visitor_id' => $locked->id, 'connection_id' => $live->id, 'ext_ref' => 'EXR-'.Str::ulid(),
            'state' => 'succeeded', 'erp_lead_id' => 77, 'succeeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get('/staff/visitors')->assertSee('Move selected to trash…')
            ->assertSee('formaction="'.route('visitors.bulk-trash.review').'"', false);

        $ids = ['visitor_ids' => [$a->id, $b->id, $locked->id]];
        $this->actingAs($admin)->post('/staff/visitors/bulk-trash/review', $ids)->assertOk()
            ->assertSeeInOrder(['Move 2 visitors to the trash?', 'Gamma Locked: Already sent to the live ERP', 'Alpha One', 'Beta Two'])
            ->assertSee('class="btn btn-danger"', false);
        $this->assertSame(0, Visitor::query()->whereNotNull('trashed_at')->count());

        $this->actingAs($admin)->post('/staff/visitors/bulk-trash', $ids)->assertRedirect('/staff/visitors')
            ->assertSessionHas('status', 'Moved 2 visitors to the trash. They can be restored for 30 days. 1 visitor was skipped: locked or being sent to the ERP.');
        $this->assertNotNull($a->fresh()->trashed_at);
        $this->assertNotNull($b->fresh()->trashed_at);
        $this->assertNull($locked->fresh()->trashed_at);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'visitor_trashed')->count());

        // Staff never see it and cannot use it.
        $staff = User::factory()->create();
        Visitor::factory()->enteredBy($staff)->create();
        $this->actingAs($staff)->get('/staff/visitors')->assertDontSee('Move selected to trash');
        $this->actingAs($staff)->post('/staff/visitors/bulk-trash/review', ['visitor_ids' => [$locked->id]])->assertForbidden();
        $this->actingAs($staff)->post('/staff/visitors/bulk-trash', ['visitor_ids' => [$locked->id]])->assertForbidden();
    }
}
