<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Asset;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Work order §7 "Save & New", §10.4 (staff part) and §10.17 (staff half).
 */
class SaveAndNewTest extends TestCase
{
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Paper Form Person', 'phone' => '0100 555 1234', 'governorate' => 'GZ'];

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function body(Exhibition $exhibition, array $values = self::VISITOR, ?string $token = null): array
    {
        return [...$values, 'exhibition_id' => (string) $exhibition->id, 'submission_token' => $token ?? (string) Str::ulid()];
    }

    public function test_a_save_returns_201_with_the_next_token_and_stores_a_staff_entry(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();
        $token = (string) Str::ulid();

        $response = $this->actingAs($staff)->postJson('/staff/registrations', $this->body($exhibition, token: $token))
            ->assertCreated()
            ->assertJson(['status' => 'saved', 'possible_duplicate' => false, 'visitor_name' => 'Paper Form Person'])
            ->assertJsonMissingPath('duplicate_url');

        $this->assertTrue(Str::isUlid($response->json('next_token')));
        $this->assertNotSame($token, $response->json('next_token'));

        $visitor = Visitor::query()->sole();
        $this->assertSame('staff', $visitor->source);
        $this->assertSame($staff->id, $visitor->entered_by);
        $this->assertSame($token, $visitor->submission_token);
        $this->assertSame('+201005551234', $visitor->phone_key);
    }

    public function test_staff_enter_visitors_for_closed_exhibitions_and_while_public_registration_is_off(): void
    {
        $staff = User::factory()->staff()->create();
        $closed = Exhibition::factory()->closed()->create();
        app(Settings::class)->set('public_registration_enabled', false);

        $this->actingAs($staff)->postJson('/staff/registrations', $this->body($closed))->assertCreated();
        $this->actingAs($staff)->get('/staff/entry?exhibition='.$closed->id)->assertOk()->assertSee($closed->name)->assertSee('Closed to the public');

        // The public form refuses both, as before.
        $this->get('/r/'.$closed->public_token)->assertOk()->assertDontSee('name="submission_token"', false);
        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_a_replay_by_the_same_user_returns_200_and_creates_nothing(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();
        $body = $this->body($exhibition);

        $this->actingAs($staff)->postJson('/staff/registrations', $body)->assertCreated();
        $this->actingAs($staff)->postJson('/staff/registrations', [...$body, 'phone' => '+20 100 555 1234'])
            ->assertOk()->assertJson(['status' => 'replay']);

        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_a_token_committed_by_user_a_returns_409_for_user_b_and_reveals_nothing(): void
    {
        $a = User::factory()->staff()->create();
        $b = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();
        $body = $this->body($exhibition);

        $this->actingAs($a)->postJson('/staff/registrations', $body)->assertCreated();
        $this->newProcess();
        $response = $this->actingAs($b)->postJson('/staff/registrations', $body)->assertConflict();

        $this->assertStringNotContainsString('Paper Form Person', $response->getContent());
        $this->assertStringNotContainsString('555', $response->getContent());
        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_the_same_token_with_different_values_is_409_and_offers_a_new_token(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();
        $body = $this->body($exhibition);

        $this->actingAs($staff)->postJson('/staff/registrations', $body)->assertCreated();
        $conflict = $this->actingAs($staff)->postJson('/staff/registrations', [...$body, 'name' => 'Corrected Name'])->assertConflict();

        $this->actingAs($staff)->postJson('/staff/registrations', [...$body, 'name' => 'Corrected Name', 'submission_token' => $conflict->json('next_token')])
            ->assertCreated();
        $this->assertSame(2, Visitor::query()->count());
    }

    public function test_validation_errors_are_422_by_field_and_nothing_is_saved(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($staff)->postJson('/staff/registrations', $this->body($exhibition, ['name' => '', 'phone' => '12', 'email' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name', 'phone', 'email']]);

        $this->assertSame(0, Visitor::query()->count());
    }

    public function test_without_javascript_a_save_redirects_to_a_fresh_form_and_errors_keep_values_and_token(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($staff)->post('/staff/registrations', $this->body($exhibition))
            ->assertRedirect(route('staff.entry'))->assertSessionHas('status', 'Saved Paper Form Person.');

        $token = (string) Str::ulid();
        $html = $this->actingAs($staff)->post('/staff/registrations', $this->body($exhibition, ['name' => 'Kept', 'phone' => '1'], $token))
            ->assertStatus(422)
            ->assertSee('value="Kept"', false)
            ->getContent();
        $this->assertStringContainsString('name="submission_token" value="'.$token.'"', $html);
    }

    public function test_a_possible_duplicate_names_the_visitor_and_only_managers_get_the_earlier_entry_link(): void
    {
        $exhibition = Exhibition::factory()->create();
        $earlier = Visitor::factory()->for($exhibition)->create(['phone_raw' => '01005551234', 'phone_key' => '+201005551234']);

        foreach (['superAdmin', 'admin'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->postJson('/staff/registrations', $this->body($exhibition))
                ->assertCreated()
                ->assertJson(['possible_duplicate' => true, 'visitor_name' => 'Paper Form Person', 'duplicate_url' => route('visitors.show', $earlier)]);
        }

        // Staff see the flag on their own entry but never the matching record (§7).
        $staff = User::factory()->staff()->create();
        $token = (string) Str::ulid();
        $this->actingAs($staff)->postJson('/staff/registrations', $this->body($exhibition, token: $token))
            ->assertCreated()
            ->assertJson(['possible_duplicate' => true, 'visitor_name' => 'Paper Form Person'])
            ->assertJsonMissingPath('duplicate_url');

        // A replay answers the same way.
        $this->actingAs($staff)->postJson('/staff/registrations', $this->body($exhibition, token: $token))
            ->assertOk()
            ->assertJson(['status' => 'replay', 'possible_duplicate' => true, 'visitor_name' => 'Paper Form Person'])
            ->assertJsonMissingPath('duplicate_url');
        $admin = User::factory()->admin()->create();
        $adminToken = (string) Str::ulid();
        $this->actingAs($admin)->postJson('/staff/registrations', $this->body($exhibition, token: $adminToken))->assertCreated();
        $this->actingAs($admin)->postJson('/staff/registrations', $this->body($exhibition, token: $adminToken))
            ->assertOk()
            ->assertJson(['status' => 'replay', 'duplicate_url' => route('visitors.show', $earlier)]);

        // Without JavaScript the warning comes back as a flash message.
        $this->actingAs($staff)->post('/staff/registrations', $this->body($exhibition))
            ->assertRedirect(route('staff.entry'))
            ->assertSessionHas('warning', 'Saved Paper Form Person. This phone number is already registered at this exhibition.');
    }

    public function test_the_exhibition_choice_is_kept_in_the_session(): void
    {
        $staff = User::factory()->staff()->create();
        [$a, $b] = Exhibition::factory()->count(2)->create();

        $this->actingAs($staff)->get('/staff/entry')->assertOk()->assertSee('Which exhibition');
        $this->actingAs($staff)->get('/staff/entry?exhibition='.$b->id)->assertOk()->assertSee($b->name);
        $this->actingAs($staff)->get('/staff/entry')->assertOk()->assertSee($b->name)->assertSee('name="exhibition_id" value="'.$b->id.'"', false);
        $this->actingAs($staff)->get('/staff/entry?change=1')->assertOk()->assertSee('Which exhibition');
    }

    public function test_session_endpoint_gives_a_csrf_token_and_expired_sessions_get_401(): void
    {
        $this->getJson('/staff/session')->assertUnauthorized();
        $this->postJson('/staff/registrations', [])->assertUnauthorized();

        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->getJson('/staff/session')->assertOk()->assertJsonStructure(['csrf_token']);
    }

    public function test_the_16_kb_cap_applies_to_staff_entry(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($staff)->postJson('/staff/registrations', $this->body($exhibition, [...self::VISITOR, 'name' => str_repeat('x', 17000)]))
            ->assertStatus(413);
        $this->assertSame(0, Visitor::query()->count());
    }

    public function test_the_entry_page_loads_its_script_and_no_browser_storage_is_used(): void
    {
        $staff = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($staff)->get('/staff/entry?exhibition='.$exhibition->id)
            ->assertSee(Asset::url('entry.js'), false)
            ->assertSee('data-entry', false);

        $script = (string) file_get_contents(public_path('assets/entry.js'));
        $this->assertDoesNotMatchRegularExpression('/localStorage|sessionStorage|indexedDB|document\.cookie/', $script);
    }
}
