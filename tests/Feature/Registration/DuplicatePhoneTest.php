<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Registration\PhoneLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M9 CO-7: one QR registration per phone number per exhibition. The race
 * tests are in DuplicatePhoneRaceTest.
 */
class DuplicatePhoneTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Invented Visitor', 'phone' => '01001234567'];

    public function test_a_second_public_registration_with_the_same_number_is_refused_in_any_notation(): void
    {
        $exhibition = Exhibition::factory()->english()->create(['name' => 'Delta Farm Days']);
        $this->submitPublicForm($exhibition, self::VISITOR)->assertRedirect();

        foreach (['01001234567', '+20 100 123 4567', '٠١٠٠١٢٣٤٥٦٧', '00201001234567'] as $typed) {
            $html = $this->submitPublicForm($exhibition, ['name' => 'Someone Else', 'phone' => $typed])->assertStatus(422)->getContent();
            $this->assertStringContainsString('This number is already registered at Delta Farm Days. Please see a member of our team.', $html);
            $this->assertStringContainsString('value="Someone Else"', $html, 'The typed values are kept.');
            $this->assertStringNotContainsString('Invented Visitor', $html, 'Nothing about the earlier visitor is shown.');
            $this->assertStringNotContainsString(Visitor::query()->sole()->package_code, $html);
        }
        $this->assertSame(1, Visitor::query()->count());

        // The message is in Arabic on an Arabic page.
        $arabic = Exhibition::factory()->create(['name' => 'معرض تجريبي']);
        $this->submitPublicForm($arabic, self::VISITOR)->assertRedirect();
        $this->submitPublicForm($arabic, self::VISITOR)->assertStatus(422)
            ->assertSee(__('public.error.phone_taken', ['exhibition' => 'معرض تجريبي'], 'ar'));
    }

    public function test_another_exhibition_a_trashed_earlier_visitor_and_staff_entry_are_not_blocked(): void
    {
        [$a, $b] = Exhibition::factory()->count(2)->create();
        $this->submitPublicForm($a, self::VISITOR)->assertRedirect();

        // The same number at another exhibition.
        $this->submitPublicForm($b, self::VISITOR)->assertRedirect();

        // Staff entry saves and flags it.
        $staff = User::factory()->staff()->create();
        $this->actingAs($staff)->postJson('/staff/registrations', [...self::VISITOR, 'exhibition_id' => (string) $a->id, 'submission_token' => (string) Str::ulid()])
            ->assertCreated()->assertJson(['possible_duplicate' => true]);

        // A trashed earlier visitor does not block.
        Visitor::query()->where('exhibition_id', $a->id)->update(['trashed_at' => now()]);
        auth()->logout();
        $this->submitPublicForm($a, self::VISITOR)->assertRedirect();
        $this->assertSame(4, Visitor::query()->count());
    }

    public function test_a_replay_of_the_first_submission_still_returns_thanks(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);
        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        // A double press or a resent form: the committed-replay rule runs first.
        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_the_lock_name_fits_mariadb(): void
    {
        $this->assertLessThanOrEqual(64, strlen(PhoneLock::name(PHP_INT_MAX, '+201001234567')));
    }
}
