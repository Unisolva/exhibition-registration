<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Http\Controllers\PublicRegistrationController;
use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Crockford;
use App\Support\PackageCode;
use App\Support\QrCode;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M9 CO-4 to CO-6: package settings per exhibition, a package code for every
 * visitor, and the thank-you screen.
 */
class WelcomePackageTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Invented Visitor', 'phone' => '01001234567'];

    public function test_co4_an_admin_sets_the_package_and_its_notes(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'Package Fair', 'starts_on' => '2026-10-26', 'default_locale' => 'ar'];

        $this->actingAs($admin)->get('/staff/exhibitions/create')->assertOk()
            ->assertSee('Offers a welcome package')->assertSee('data-package-notes', false)->assertSee('Package note in Arabic');
        $this->actingAs($admin)->post('/staff/exhibitions', [...$base, 'offers_package' => '1', 'package_note_en' => str_repeat('x', 501)])
            ->assertSessionHasErrors('package_note_en');
        $this->actingAs($admin)->post('/staff/exhibitions', [...$base, 'offers_package' => '1',
            'package_note_ar' => "هديتك\r\nفي جناحنا", 'package_note_en' => '  '])->assertRedirect();

        $exhibition = Exhibition::query()->sole();
        $this->assertTrue($exhibition->offers_package);
        $this->assertSame("هديتك\nفي جناحنا", $exhibition->package_note_ar, 'Line breaks are kept.');
        $this->assertNull($exhibition->package_note_en);
        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}")->assertSee('Welcome package: offered');

        $this->actingAs($admin)->put("/staff/exhibitions/{$exhibition->id}", $base)->assertRedirect();
        $this->assertFalse($exhibition->fresh()->offers_package);
    }

    public function test_co5_every_visitor_gets_a_unique_six_character_crockford_code(): void
    {
        $exhibition = Exhibition::factory()->create();
        $this->submitPublicForm($exhibition, self::VISITOR)->assertRedirect();
        $this->actingAs(User::factory()->staff()->create())->postJson('/staff/registrations', [
            'name' => 'Staff Entry', 'phone' => '01009998877', 'exhibition_id' => (string) $exhibition->id, 'submission_token' => (string) Str::ulid(),
        ])->assertCreated();
        Visitor::factory()->count(20)->create();

        $codes = Visitor::query()->pluck('package_code');
        $this->assertCount(22, $codes);
        $this->assertCount(22, $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^['.Crockford::ALPHABET.']{6}$/', $code);
        }
        // Typed codes are read as Crockford does: any case, O as 0, I and L as 1.
        $this->assertSame('ABC1D0', PackageCode::normalize(' abcldo '));
        $this->assertSame('ABC1D0', PackageCode::normalize('ABCID0'));
        $this->assertNull(PackageCode::normalize('abc'));
    }

    public function test_co5_backfill_fills_missing_codes_and_never_overwrites(): void
    {
        $kept = Visitor::factory()->create();
        $before = $kept->package_code;
        $missing = Visitor::factory()->count(3)->create();
        DB::table('visitors')->whereIn('id', $missing->pluck('id'))->update(['package_code' => null]);

        // ProductSeeder runs on every deploy (after a rollback, older code inserts visitors without one).
        $this->seed(ProductSeeder::class);

        $this->assertSame($before, $kept->fresh()->package_code);
        $this->assertSame(0, Visitor::query()->whereNull('package_code')->count());
        $this->assertSame(0, PackageCode::backfill(), 'A second run has nothing to do.');
    }

    public function test_co6_the_thank_you_screen_shows_this_visitors_code_qr_and_note_and_a_refresh_keeps_it(): void
    {
        $exhibition = Exhibition::factory()->english()->withPackage('Your gift is at stand B12.', '')->create(['name' => 'Delta Farm Days']);
        $this->submitPublicForm($exhibition, self::VISITOR)->assertRedirect();
        $code = Visitor::query()->sole()->package_code;

        foreach ([1, 2] as $visit) {
            $html = $this->get('/r/'.$exhibition->public_token.'/thanks')->assertOk()->getContent();
            $this->assertStringContainsString(e("You're registered for Delta Farm Days. Please save this page as a screenshot and show it to a member of our team."), $html);
            $this->assertStringContainsString('<p class="package-note">Your gift is at stand B12.</p>', $html);
            $this->assertStringContainsString('>'.$code.'</p>', $html);
            $this->assertStringContainsString(QrCode::svg(PublicRegistrationController::packageUrl($code)), $html, "visit {$visit}: the QR encodes the hand-over URL");
            $this->assertStringContainsString('Show this code or the screenshot to our team to receive your gift.', $html);
            $this->assertStringNotContainsString('Register another person', $html);
            $this->assertStringNotContainsString('href="/r/'.$exhibition->public_token.'"', $html, 'No link back to the form.');
        }
        $this->assertSame(rtrim(config('app.url'), '/').'/staff/packages/'.$code, PublicRegistrationController::packageUrl($code));

        // Arabic page, empty Arabic note: the English note is shown.
        $this->get('/r/'.$exhibition->public_token.'/thanks?lang=ar')->assertSee('Your gift is at stand B12.')
            ->assertSee('اعرض الرمز أو لقطة الشاشة على فريقنا لاستلام هديتك.');
    }

    public function test_co6_without_the_session_without_a_package_or_for_a_staff_entry_the_generic_thanks(): void
    {
        $package = Exhibition::factory()->english()->withPackage()->create(['name' => 'Delta Farm Days']);
        $this->submitPublicForm($package, self::VISITOR)->assertRedirect();
        $code = Visitor::query()->sole()->package_code;

        // Another browser (no session): the generic thanks.
        $this->newProcess();
        $this->get('/r/'.$package->public_token.'/thanks')->assertOk()
            ->assertSee("You're registered for Delta Farm Days. Our team will contact you soon.")->assertDontSee($code)->assertDontSee('package-box', false);

        // An exhibition without a package.
        $plain = Exhibition::factory()->english()->create(['name' => 'Plain Fair']);
        $this->submitPublicForm($plain, self::VISITOR)->assertRedirect();
        $this->get('/r/'.$plain->public_token.'/thanks')->assertSee("You're registered for Plain Fair.")->assertDontSee('package-box', false);

        // A staff entry's code is never shown on a public page.
        $staffEntry = Visitor::factory()->at($package)->enteredBy(User::factory()->staff()->create())->create();
        $this->withSession(['public_visitor.'.$package->public_token => $staffEntry->id])
            ->get('/r/'.$package->public_token.'/thanks')->assertDontSee($staffEntry->package_code)->assertDontSee('package-box', false);
    }
}
