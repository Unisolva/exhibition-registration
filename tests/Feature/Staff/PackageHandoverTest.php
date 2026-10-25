<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Http\Controllers\Staff\EntryController;
use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * M9 CO-8 to CO-11: package hand-over by code or exact phone, the minimal
 * card, marking and undoing, Save & New's "Package given", the visitor page,
 * list and export.
 */
class PackageHandoverTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    private function qrVisitor(Exhibition $exhibition, array $attributes = []): Visitor
    {
        return Visitor::factory()->at($exhibition)->create(['source' => 'public', 'name' => 'Nour Sample', 'email' => 'nour@example.test',
            'phone_raw' => '01001234567', 'phone_e164' => '+201001234567', 'phone_key' => '+201001234567', ...$attributes]);
    }

    private function withExhibition(User $user, Exhibition $exhibition): static
    {
        return $this->actingAs($user)->withSession([EntryController::SESSION_KEY => $exhibition->id]);
    }

    private function events(string $action): int
    {
        return DB::table('audit_events')->where('action', $action)->count();
    }

    public function test_staff_hand_over_a_qr_registration_by_code_and_still_cannot_open_it_anywhere_else(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create(['name' => 'Package Fair']);
        $visitor = $this->qrVisitor($exhibition);
        $staff = User::factory()->staff()->create(['name' => 'Hala Staff']);

        // A typed code is read as Crockford does, then shown on the same page as the QR opens.
        $typed = strtolower(str_replace(['0', '1'], ['o', 'l'], $visitor->package_code));
        $this->withExhibition($staff, $exhibition)->get('/staff/packages/find?code='.$typed)->assertRedirect(route('packages.show', $visitor->package_code));

        $html = $this->actingAs($staff)->get("/staff/packages/{$visitor->package_code}")->assertOk()
            ->assertSee('Nour Sample')->assertSee($visitor->package_code)->assertSee('Package Fair')->assertSee('QR form')
            ->assertSee('Mark package received')->getContent();
        foreach (['01001234567', '+201001234567', 'nour@example.test', route('visitors.show', $visitor)] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, 'The card shows nothing more than its fields.');
        }

        $this->actingAs($staff)->post("/staff/packages/{$visitor->package_code}/give")->assertRedirect();
        $visitor->refresh();
        $this->assertNotNull($visitor->package_received_at);
        $this->assertSame($staff->id, $visitor->package_received_by);
        $this->actingAs($staff)->get("/staff/packages/{$visitor->package_code}")
            ->assertSee('Package already received')->assertSee('by Hala Staff')->assertDontSee('Mark package received');

        // Nowhere else.
        $this->actingAs($staff)->get("/staff/visitors/{$visitor->id}")->assertForbidden();
        $this->actingAs($staff)->get("/staff/visitors/{$visitor->id}/edit")->assertForbidden();
        $this->actingAs($staff)->get('/staff/visitors')->assertDontSee('Nour Sample');
        $this->actingAs($staff)->get('/staff/visitors/export')->assertForbidden();
    }

    public function test_not_found_trashed_deleted_and_no_package_read_the_same(): void
    {
        $package = Exhibition::factory()->withPackage()->create();
        $plain = Exhibition::factory()->create();
        $staff = User::factory()->staff()->create();
        $trashed = $this->qrVisitor($package, ['trashed_at' => now()]);
        $deleted = $this->qrVisitor($package, ['deleted_at' => now(), 'phone_key' => '+201009999999']);
        $noPackage = $this->qrVisitor($plain);

        foreach (['ZZZZZZ', $trashed->package_code, $deleted->package_code, $noPackage->package_code, 'not-a-code'] as $code) {
            $this->actingAs($staff)->get('/staff/packages/'.$code)->assertOk()->assertSee('No package found for this code.')->assertDontSee('Mark package received');
        }
        $this->actingAs($staff)->post("/staff/packages/{$noPackage->package_code}/give")->assertNotFound();
        $this->assertNull($noPackage->fresh()->package_received_at);
        $this->assertSame(5, DB::table('audit_events')->where('action', 'package.lookup')->where('outcome', 'not_found')->count());
    }

    public function test_phone_search_is_exact_within_the_exhibition_and_shows_one_card_per_match(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $other = Exhibition::factory()->withPackage()->create();
        $staff = User::factory()->staff()->create();
        $first = $this->qrVisitor($exhibition);
        $second = Visitor::factory()->at($exhibition)->enteredBy($staff)->create(['name' => 'Second Entry',
            'phone_raw' => '01001234567', 'phone_e164' => '+201001234567', 'phone_key' => '+201001234567', 'possible_duplicate_of' => $first->id]);
        $this->qrVisitor($other, ['name' => 'Elsewhere']);

        foreach (['01001234567', '+20 100 123 4567', '٠١٠٠١٢٣٤٥٦٧', '00201001234567'] as $typed) {
            $this->withExhibition($staff, $exhibition)->get('/staff/packages/phone?phone='.urlencode($typed))->assertOk()
                ->assertSee('2 registrations have this number')->assertSee('Nour Sample')->assertSee('Second Entry')->assertDontSee('Elsewhere')
                ->assertSee('Check before handing over a second package.');
        }
        foreach (['0100123', '1234567', ''] as $partial) {
            $this->withExhibition($staff, $exhibition)->get('/staff/packages/phone?phone='.urlencode($partial))->assertOk()
                ->assertSee('No package found for this number.')->assertDontSee('Nour Sample');
        }

        // The log never holds the typed number.
        $meta = DB::table('audit_events')->where('action', 'package.lookup')->pluck('meta')->implode(' ');
        $this->assertStringNotContainsString('1234567', $meta);
        $this->assertStringContainsString('"method":"phone"', $meta);
    }

    public function test_the_qr_link_asks_for_sign_in_then_comes_back(): void
    {
        $visitor = $this->qrVisitor(Exhibition::factory()->withPackage()->create());
        $staff = User::factory()->staff()->create();

        $this->get("/staff/packages/{$visitor->package_code}")->assertRedirect(route('login'));
        $this->post('/staff/login', ['email' => $staff->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect(url("/staff/packages/{$visitor->package_code}"));
    }

    public function test_lookups_are_limited_to_30_a_minute_per_user(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $staff = User::factory()->staff()->create();
        for ($i = 0; $i < 15; $i++) {
            $this->actingAs($staff)->get('/staff/packages/ZZZZZZ')->assertOk();
            $this->withExhibition($staff, $exhibition)->get('/staff/packages/phone?phone=01001234567')->assertOk();
        }
        $this->actingAs($staff)->get('/staff/packages/ZZZZZZ')->assertStatus(429);
        $this->actingAs(User::factory()->staff()->create())->get('/staff/packages/ZZZZZZ')->assertOk();
    }

    public function test_undo_by_the_marker_within_10_minutes_and_by_admins_any_time(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $marker = User::factory()->staff()->create();
        $colleague = User::factory()->staff()->create();
        $admin = User::factory()->admin()->create();

        $visitor = $this->qrVisitor($exhibition);
        $this->actingAs($marker)->post("/staff/packages/{$visitor->package_code}/give");
        $this->travel(9)->minutes();
        $this->actingAs($colleague)->post("/staff/packages/{$visitor->package_code}/undo")->assertForbidden();
        $this->actingAs($marker)->get("/staff/packages/{$visitor->package_code}")->assertSee('>Undo</button>', false);
        $this->actingAs($marker)->post("/staff/packages/{$visitor->package_code}/undo")->assertRedirect();
        $this->assertNull($visitor->fresh()->package_received_at);

        $this->actingAs($marker)->post("/staff/packages/{$visitor->package_code}/give");
        $this->travel(11)->minutes();
        $this->actingAs($marker)->get("/staff/packages/{$visitor->package_code}")->assertDontSee('>Undo</button>', false);
        $this->actingAs($marker)->post("/staff/packages/{$visitor->package_code}/undo")->assertForbidden();
        $this->assertNotNull($visitor->fresh()->package_received_at);

        $this->travel(3)->days();
        $this->actingAs($admin)->post("/staff/packages/{$visitor->package_code}/undo")->assertRedirect();
        $this->assertNull($visitor->fresh()->package_received_at);
        $this->assertSame([2, 2], [$this->events('package.given'), $this->events('package.undone')]);
    }

    public function test_mark_and_undo_work_on_a_visitor_locked_after_a_live_push_while_edit_stays_refused(): void
    {
        $live = $this->readyToPush('live');
        $exhibition = Exhibition::factory()->withPackage()->create();
        $visitor = $this->qrVisitor($exhibition);
        $hash = $visitor->payload_hash;
        DB::table('lead_syncs')->insert(['visitor_id' => $visitor->id, 'connection_id' => $live->id, 'ext_ref' => 'EXR-'.Str::ulid(),
            'state' => 'succeeded', 'erp_lead_id' => 77, 'succeeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => 'Changed', 'phone' => '01001234567'])->assertStatus(423);
        $this->actingAs($admin)->post("/staff/packages/{$visitor->package_code}/give")->assertRedirect();
        $this->assertNotNull($visitor->fresh()->package_received_at);
        $this->actingAs($admin)->post("/staff/packages/{$visitor->package_code}/undo")->assertRedirect();
        $this->assertNull($visitor->fresh()->package_received_at);
        $this->assertSame($hash, $visitor->fresh()->payload_hash, 'Package data never changes payload_hash.');
        $this->actingAs($admin)->delete("/staff/visitors/{$visitor->id}")->assertStatus(423);
    }

    public function test_a_double_press_on_mark_records_one_event(): void
    {
        $visitor = $this->qrVisitor(Exhibition::factory()->withPackage()->create());
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->post("/staff/packages/{$visitor->package_code}/give");
        $this->actingAs($staff)->post("/staff/packages/{$visitor->package_code}/give");
        $this->assertSame(1, $this->events('package.given'));
    }

    public function test_save_and_new_package_given_is_recorded_with_the_entry_and_never_twice(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $plain = Exhibition::factory()->create();
        $staff = User::factory()->staff()->create();

        $this->withExhibition($staff, $exhibition)->get('/staff/entry')->assertSee('Package given');
        $this->withExhibition($staff, $plain)->get('/staff/entry')->assertDontSee('Package given');

        $body = ['name' => 'Desk Visitor', 'phone' => '01005556666', 'exhibition_id' => (string) $exhibition->id,
            'submission_token' => (string) Str::ulid(), 'package_given' => '1'];
        $this->actingAs($staff)->postJson('/staff/registrations', $body)->assertCreated();
        $this->actingAs($staff)->postJson('/staff/registrations', $body)->assertOk()->assertJson(['status' => 'replay']);
        $visitor = Visitor::query()->where('name', 'Desk Visitor')->sole();
        $this->assertSame($staff->id, $visitor->package_received_by);
        $this->assertSame(1, $this->events('package.given'));

        // Not ticked, or an exhibition without a package: nothing recorded.
        $this->actingAs($staff)->postJson('/staff/registrations', [...$body, 'name' => 'No Package', 'submission_token' => (string) Str::ulid(), 'package_given' => null]);
        $this->actingAs($staff)->postJson('/staff/registrations', [...$body, 'name' => 'Plain Fair', 'exhibition_id' => (string) $plain->id, 'submission_token' => (string) Str::ulid()]);
        $this->assertSame(1, Visitor::query()->whereNotNull('package_received_at')->count());
    }

    public function test_visitor_page_list_filter_and_export(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $admin = User::factory()->admin()->create(['name' => 'Admin Person']);
        $given = $this->qrVisitor($exhibition, ['name' => 'Given Visitor']);
        $waiting = $this->qrVisitor($exhibition, ['name' => 'Waiting Visitor', 'phone_key' => '+201002223333']);
        $this->actingAs($admin)->post("/staff/packages/{$given->package_code}/give");

        $this->actingAs($admin)->get("/staff/visitors/{$given->id}")->assertOk()
            ->assertSee('<h2>Welcome package</h2>', false)->assertSee($given->package_code)
            ->assertSee('Received')->assertSee('by Admin Person')->assertSee('Marked received by Admin Person')->assertSee('>Undo</button>', false);

        $this->actingAs($admin)->get('/staff/visitors')->assertSee('<th>Package</th>', false)
            ->assertSee('<span class="badge bg-success-lt">Received</span>', false)->assertSee('<span class="badge bg-secondary-lt">Not received</span>', false);
        $this->actingAs($admin)->get('/staff/visitors?package=received')->assertSee('Given Visitor')->assertDontSee('Waiting Visitor');
        $this->actingAs($admin)->get('/staff/visitors?package=not_received')->assertSee('Waiting Visitor')->assertDontSee('Given Visitor');

        $csv = $this->actingAs($admin)->get('/staff/visitors/export')->streamedContent();
        $this->assertStringContainsString('"Package code","Package received at","Package received by"', $csv);
        $this->assertStringContainsString($given->package_code, $csv);
        $this->assertStringContainsString('Admin Person', $csv);
    }
}
