<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Exhibitions;

use App\Http\Middleware\GuardPublicSubmission;
use App\Models\Exhibition;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M8 CO-6 to CO-8: each exhibition has a default public language (Arabic or
 * English); `?lang=` then the visitor's choice for that exhibition, then the
 * default, decide the language of its public pages.
 */
class PublicLanguageTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const AR = '<html lang="ar" dir="rtl">';

    private const EN = '<html lang="en" dir="ltr">';

    public function test_the_column_is_arabic_by_default_and_new_exhibitions_start_with_arabic_selected(): void
    {
        $this->assertTrue(Schema::hasColumn('exhibitions', 'default_locale'));
        $exhibition = new Exhibition(['name' => 'No Language Given', 'starts_on' => '2026-11-01']);
        $exhibition->created_by = User::factory()->admin()->create()->id;
        $exhibition->save();
        $this->assertSame('ar', $exhibition->fresh()->default_locale);

        $this->actingAs(User::factory()->admin()->create())->get('/staff/exhibitions/create')
            ->assertOk()
            ->assertSee('Public form language')
            ->assertSee('<option value="ar" selected>Arabic</option>', false);
    }

    public function test_the_language_is_chosen_at_creation_validated_editable_and_shown(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['name' => 'English Fair', 'starts_on' => '2026-11-03', 'ends_on' => ''];

        $this->actingAs($admin)->post('/staff/exhibitions', [...$base, 'default_locale' => 'fr'])->assertSessionHasErrors('default_locale');
        $this->actingAs($admin)->post('/staff/exhibitions', $base)->assertSessionHasErrors('default_locale');
        $this->actingAs($admin)->post('/staff/exhibitions', [...$base, 'default_locale' => 'en'])->assertRedirect();
        $exhibition = Exhibition::query()->sole();
        $this->assertSame('en', $exhibition->default_locale);

        $this->actingAs($admin)->get('/staff/exhibitions')->assertSee('Form language')->assertSee('<td data-label="Form language">English</td>', false);
        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}")->assertSee('Public form language: English');
        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}/edit")->assertSee('<option value="en" selected>English</option>', false);

        $this->actingAs($admin)->put("/staff/exhibitions/{$exhibition->id}", [...$base, 'default_locale' => 'ar'])->assertRedirect();
        $this->assertSame('ar', $exhibition->fresh()->default_locale);
        $this->assertDatabaseHas('audit_events', ['action' => 'exhibition_updated', 'target_id' => $exhibition->id]);
    }

    public function test_an_english_default_exhibition_opens_in_english_and_lang_ar_is_remembered_for_that_exhibition_only(): void
    {
        $english = Exhibition::factory()->english()->create();
        $arabic = Exhibition::factory()->create();

        // No session yet: the exhibition's default.
        $this->get('/r/'.$english->public_token)->assertOk()->assertSee(self::EN, false);
        $this->get('/r/'.$arabic->public_token)->assertOk()->assertSee(self::AR, false);

        // ?lang=ar switches the English one and is remembered for it.
        $this->get('/r/'.$english->public_token.'?lang=ar')->assertOk()->assertSee(self::AR, false);
        $this->get('/r/'.$english->public_token)->assertOk()->assertSee(self::AR, false);

        // ... but not for another exhibition in the same session, which keeps its own default.
        $other = Exhibition::factory()->english()->create();
        $this->get('/r/'.$other->public_token)->assertOk()->assertSee(self::EN, false);
        $this->get('/r/'.$arabic->public_token.'?lang=en')->assertOk()->assertSee(self::EN, false);
        $this->get('/r/'.$english->public_token)->assertOk()->assertSee(self::AR, false);
        $this->get('/r/'.$other->public_token)->assertOk()->assertSee(self::EN, false);

        // Only the per-exhibition key is stored; there is no global choice.
        $this->assertNull(session('public_locale.'.$other->public_token));
        $this->assertSame('ar', session('public_locale.'.$english->public_token));
        $this->assertSame([$english->public_token, $arabic->public_token], array_keys(session('public_locale')));
    }

    public function test_an_invalid_lang_value_is_ignored(): void
    {
        $english = Exhibition::factory()->english()->create();

        $this->get('/r/'.$english->public_token.'?lang=fr')->assertOk()->assertSee(self::EN, false);
        $this->assertNull(session('public_locale.'.$english->public_token));
        $this->get('/r/'.$english->public_token.'?lang[]=ar')->assertOk()->assertSee(self::EN, false);
    }

    public function test_thanks_closed_and_rate_limited_pages_follow_the_exhibition(): void
    {
        $english = Exhibition::factory()->english()->create();
        $this->submitPublicForm($english, ['name' => 'Invented Visitor', 'phone' => '01001234567'])->assertRedirect();
        $this->get('/r/'.$english->public_token.'/thanks')->assertOk()->assertSee(self::EN, false)->assertSee(__('public.thanks_title', [], 'en'));

        $closed = Exhibition::factory()->english()->closed()->create();
        $this->get('/r/'.$closed->public_token)->assertOk()->assertSee(self::EN, false)->assertSee(__('public.closed_title', [], 'en'));

        // A replay with other values (409) and a rate limit (429) answer in the exhibition's language too.
        $hidden = $this->openPublicForm($english);
        $this->submitPublicForm($english, ['name' => 'First Person', 'phone' => '01001234569'], $hidden)->assertRedirect();
        $this->submitPublicForm($english, ['name' => 'Other Person', 'phone' => '01001234569'], $hidden)->assertStatus(409)->assertSee(self::EN, false);
        for ($i = 0; $i < GuardPublicSubmission::PER_EXHIBITION_PER_MINUTE; $i++) {
            RateLimiter::hit('public-submit-exhibition:'.$english->public_token, 60);
        }
        $this->submitPublicForm($english, ['name' => 'Third Person', 'phone' => '01001234568'])->assertStatus(429)
            ->assertSee(self::EN, false)->assertSee(__('public.rate_limited', [], 'en'));

        app(Settings::class)->set('public_registration_enabled', false);
        $arabic = Exhibition::factory()->create();
        $this->get('/r/'.$arabic->public_token)->assertOk()->assertSee(self::AR, false)->assertSee(__('public.closed_title', [], 'ar'));
    }

    public function test_an_unknown_token_gets_the_bilingual_404_and_stores_nothing(): void
    {
        $this->get('/r/ZZZZZZZZZZ?lang=en')->assertNotFound();
        $this->assertNull(session('public_locale'));
    }

    public function test_the_poster_puts_the_default_language_first(): void
    {
        $settings = app(Settings::class);
        $settings->set('org_name_en', 'Sample Agri Company');
        $settings->set('org_name_ar', 'شركة زراعية تجريبية');
        $admin = User::factory()->admin()->create();

        $english = Exhibition::factory()->english()->create();
        $this->actingAs($admin)->get("/staff/exhibitions/{$english->id}/poster")->assertOk()
            ->assertSeeInOrder(['<p class="poster-org" lang="en" dir="ltr">Sample Agri Company</p>', '<p class="poster-org-second" lang="ar" dir="rtl">شركة زراعية تجريبية</p>'], false);

        $arabic = Exhibition::factory()->create();
        $this->actingAs($admin)->get("/staff/exhibitions/{$arabic->id}/poster")->assertOk()
            ->assertSeeInOrder(['<p class="poster-org" lang="ar" dir="rtl">شركة زراعية تجريبية</p>', '<p class="poster-org-second" lang="en" dir="ltr">Sample Agri Company</p>'], false);
    }
}
