<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Http\Controllers\PublicRegistrationController;
use App\Http\Middleware\GuardPublicSubmission;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Support\Asset;
use App\Support\Settings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * Work order §6 and §10.3, §10.4 (public), §10.5, §10.13, §10.17 (public half).
 */
class PublicFormTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Invented Visitor', 'phone' => '01001234567', 'governorate' => 'C'];

    public function test_an_invalid_token_is_404_without_exhibition_data(): void
    {
        $exhibition = Exhibition::factory()->create(['name' => 'Hidden Fair Name']);

        foreach (['/r/0000000000', '/r/'.strtolower($exhibition->public_token).'X', '/r/short', '/r/0000000000/thanks'] as $url) {
            $this->get($url)->assertNotFound()->assertDontSee('Hidden Fair Name');
        }
        $this->post('/r/0000000000', self::VISITOR)->assertNotFound();
    }

    public function test_the_form_shows_branding_exhibition_and_active_fields_in_arabic_by_default(): void
    {
        $exhibition = Exhibition::factory()->create(['name' => 'Delta Agri Expo']);
        app(Settings::class)->set('org_name_ar', 'شركة تجريبية');
        app(Settings::class)->set('org_name_en', 'Sample Company');

        $this->get('/r/'.$exhibition->public_token)
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false)
            ->assertSee('شركة تجريبية')
            ->assertSee('Delta Agri Expo')
            ->assertSee('الاسم')
            ->assertSee('inputmode="tel"', false)
            ->assertSee('type="email"', false)
            ->assertSee('القاهرة')
            ->assertDontSee('consent', false);
    }

    public function test_the_english_link_switches_and_the_choice_is_remembered(): void
    {
        $exhibition = Exhibition::factory()->create();

        $this->get('/r/'.$exhibition->public_token.'?lang=en')->assertSee('<html lang="en" dir="ltr">', false)->assertSee('Name');
        $this->get('/r/'.$exhibition->public_token)->assertSee('<html lang="en" dir="ltr">', false);
        $this->get('/r/'.$exhibition->public_token.'?lang=ar')->assertSee('<html lang="ar" dir="rtl">', false);
    }

    public function test_a_valid_submission_is_stored_once_and_redirects_303_to_thanks(): void
    {
        $exhibition = Exhibition::factory()->create();

        $this->submitPublicForm($exhibition, [...self::VISITOR, 'email' => 'Person@EXAMPLE.test'])
            ->assertStatus(303)
            ->assertRedirect('/r/'.$exhibition->public_token.'/thanks');

        $visitor = Visitor::query()->sole();
        $this->assertSame($exhibition->id, $visitor->exhibition_id);
        $this->assertSame('public', $visitor->source);
        $this->assertNull($visitor->entered_by);
        $this->assertSame('+201001234567', $visitor->phone_key);
        $this->assertSame('Person@example.test', $visitor->email);
        $this->assertSame('ar', $visitor->locale);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $visitor->request_id);

        $this->get('/r/'.$exhibition->public_token.'/thanks')->assertOk()->assertDontSee('Invented Visitor');
    }

    public function test_two_exhibitions_receive_their_own_registrations(): void
    {
        [$a, $b] = Exhibition::factory()->count(2)->create();

        $this->submitPublicForm($a, [...self::VISITOR, 'name' => 'At A'])->assertStatus(303);
        $this->submitPublicForm($b, [...self::VISITOR, 'name' => 'At B'])->assertStatus(303);

        $this->assertSame('At A', Visitor::query()->where('exhibition_id', $a->id)->sole()->name);
        $this->assertSame('At B', Visitor::query()->where('exhibition_id', $b->id)->sole()->name);
    }

    public function test_the_duplicate_flag_applies_within_one_exhibition_only_and_nothing_merges(): void
    {
        [$a, $b] = Exhibition::factory()->count(2)->create();

        $this->submitPublicForm($a, self::VISITOR);
        $this->submitPublicForm($a, [...self::VISITOR, 'name' => 'Same Phone', 'phone' => '+20 100 123 4567']);
        $this->submitPublicForm($b, self::VISITOR);

        $first = Visitor::query()->where('exhibition_id', $a->id)->orderBy('id')->first();
        $second = Visitor::query()->where('exhibition_id', $a->id)->orderByDesc('id')->first();
        $other = Visitor::query()->where('exhibition_id', $b->id)->sole();

        $this->assertSame(3, Visitor::query()->count());
        $this->assertNull($first->possible_duplicate_of);
        $this->assertSame($first->id, $second->possible_duplicate_of);
        $this->assertNull($other->possible_duplicate_of);
    }

    public function test_validation_errors_keep_values_and_the_same_token(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);

        $response = $this->submitPublicForm($exhibition, ['name' => 'Kept Name', 'phone' => '123', 'email' => 'bad'], $hidden)
            ->assertStatus(422)
            ->assertSee('value="Kept Name"', false)
            ->assertSee('aria-invalid="true"', false);

        $this->assertSame($hidden['submission_token'], $this->hiddenValue($response->getContent(), 'submission_token'));
        $this->assertSame(0, Visitor::query()->count());

        // Corrected values with the same token are accepted.
        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertStatus(303);
        $this->assertSame($hidden['submission_token'], Visitor::query()->sole()->submission_token);
    }

    public function test_replay_with_the_same_payload_creates_one_row_and_succeeds(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);

        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertStatus(303);
        // The same visitor typed the number with Arabic digits this time: same normalised payload.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'phone' => '٠١٠٠١٢٣٤٥٦٧'], $hidden)
            ->assertStatus(303)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');

        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_replay_with_a_different_payload_is_409_and_reveals_nothing(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);

        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertStatus(303);
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'name' => 'Someone Else'], $hidden)
            ->assertStatus(409)
            ->assertDontSee('Invented Visitor')
            ->assertDontSee('01001234567');

        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_a_committed_replay_still_succeeds_after_the_exhibition_closes(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);
        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertStatus(303);

        $exhibition->forceFill(['is_open' => false])->save();
        app(Settings::class)->set('public_registration_enabled', false);

        $this->submitPublicForm($exhibition, self::VISITOR, $hidden)->assertStatus(303);
        $this->assertSame(1, Visitor::query()->count());
    }

    public function test_a_closed_exhibition_or_disabled_registration_refuses_new_submissions(): void
    {
        $closed = Exhibition::factory()->closed()->create();
        $this->get('/r/'.$closed->public_token)->assertOk()->assertSee(__('public.closed_title', [], 'ar'))->assertDontSee('name="submission_token"', false);
        // A form rendered before the exhibition closed (the closed page has no form to take fields from).
        $rendered = PublicRegistrationController::signRenderedAt(now()->getTimestamp(), $closed);
        $this->submitPublicForm($closed, self::VISITOR, ['submission_token' => (string) str()->ulid(), 'rendered_at' => $rendered, '_token' => ''])
            ->assertForbidden();

        $open = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($open);
        app(Settings::class)->set('public_registration_enabled', false);
        $this->get('/r/'.$open->public_token)->assertSee(__('public.closed_title', [], 'ar'));
        $this->submitPublicForm($open, self::VISITOR, $hidden)->assertForbidden()->assertSee(__('public.closed_title', [], 'ar'));

        $this->assertSame(0, Visitor::query()->count());
    }

    public function test_honeypot_and_too_fast_submissions_write_nothing_show_thanks_and_log(): void
    {
        $exhibition = Exhibition::factory()->create();
        Log::spy();

        // Honeypot filled.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'website' => 'http://spam.example'])
            ->assertStatus(303)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');

        // Sent within three seconds of the page being rendered.
        $this->submitPublicForm($exhibition, self::VISITOR, wait: false)->assertStatus(303);

        // A forged or missing render time.
        $hidden = $this->openPublicForm($exhibition);
        $this->submitPublicForm($exhibition, self::VISITOR, [...$hidden, 'rendered_at' => '1.'.str_repeat('0', 64)])->assertStatus(303);
        $this->submitPublicForm($exhibition, self::VISITOR, [...$hidden, 'rendered_at' => ''])->assertStatus(303);

        $this->assertSame(0, Visitor::query()->count());
        Log::shouldHaveReceived('info')->with('spam_blocked', Mockery::type('array'))->times(4);
    }

    public function test_more_than_20_posts_a_minute_from_one_client_get_a_friendly_429_with_values_kept(): void
    {
        $exhibition = Exhibition::factory()->create();
        $hidden = $this->openPublicForm($exhibition);
        $this->travel(5)->seconds();

        for ($i = 1; $i <= GuardPublicSubmission::PER_CLIENT_PER_MINUTE; $i++) {
            $this->post('/r/'.$exhibition->public_token, [...$hidden, 'name' => '', 'phone' => ''])->assertStatus(422);
        }

        $this->post('/r/'.$exhibition->public_token, [...$hidden, 'name' => 'Still Here', 'phone' => '01001234567'])
            ->assertStatus(429)
            ->assertSee('value="Still Here"', false)
            ->assertSee(__('public.rate_limited', [], 'ar'));

        // Another client at the same exhibition is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->post('/r/'.$exhibition->public_token, [...$hidden, 'website' => '', ...self::VISITOR])
            ->assertStatus(303);
    }

    public function test_the_rate_limit_counts_before_the_csrf_check(): void
    {
        // Real CSRF checking, which the test client normally skips.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $exhibition = Exhibition::factory()->create();

        for ($i = 1; $i <= GuardPublicSubmission::PER_CLIENT_PER_MINUTE; $i++) {
            $this->post('/r/'.$exhibition->public_token, self::VISITOR)->assertStatus(419);
        }
        $this->post('/r/'.$exhibition->public_token, self::VISITOR)->assertStatus(429);
    }

    public function test_the_per_exhibition_ceiling_applies_across_clients(): void
    {
        $exhibition = Exhibition::factory()->create();
        for ($i = 0; $i < GuardPublicSubmission::PER_EXHIBITION_PER_MINUTE; $i++) {
            RateLimiter::hit('public-submit-exhibition:'.$exhibition->public_token, 60);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->submitPublicForm($exhibition, self::VISITOR)
            ->assertStatus(429);
    }

    public function test_a_request_body_over_16_kb_is_refused(): void
    {
        $exhibition = Exhibition::factory()->create();

        $this->submitPublicForm($exhibition, [...self::VISITOR, 'name' => str_repeat('x', 17000)])->assertStatus(413);
        $this->assertSame(0, Visitor::query()->count());
    }

    public function test_two_fresh_sessions_get_different_submission_and_csrf_tokens_and_no_caching(): void
    {
        $exhibition = Exhibition::factory()->create();

        $first = $this->get('/r/'.$exhibition->public_token);
        $this->newProcess();
        $second = $this->get('/r/'.$exhibition->public_token);

        foreach (['submission_token', '_token', 'rendered_at'] as $name) {
            $this->assertNotSame('', $this->hiddenValue($first->getContent(), $name));
        }
        $this->assertNotSame($this->hiddenValue($first->getContent(), 'submission_token'), $this->hiddenValue($second->getContent(), 'submission_token'));
        $this->assertNotSame($this->hiddenValue($first->getContent(), '_token'), $this->hiddenValue($second->getContent(), '_token'));

        foreach ([$first, $second, $this->get('/r/'.$exhibition->public_token.'/thanks')] as $response) {
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_inactive_and_required_custom_fields(): void
    {
        $exhibition = Exhibition::factory()->create();
        FieldDefinition::query()->where('key', 'email')->update(['is_active' => false]);
        FieldDefinition::query()->create([
            'key' => 'crop', 'is_system' => false, 'type' => 'select', 'label_en' => 'Main crop', 'label_ar' => 'المحصول الرئيسي',
            'options' => [['value' => 'wheat', 'label_en' => 'Wheat', 'label_ar' => 'قمح', 'is_active' => true]],
            'is_required' => true, 'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true,
        ]);

        $this->get('/r/'.$exhibition->public_token)->assertDontSee('name="email"', false)->assertSee('قمح');

        $this->submitPublicForm($exhibition, self::VISITOR)->assertStatus(422)->assertSee('المحصول الرئيسي');
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'crop' => 'wheat', 'email' => 'ignored@example.test'])->assertStatus(303);

        $visitor = Visitor::query()->sole();
        $this->assertSame(['crop' => 'wheat'], $visitor->extra);
        $this->assertNull($visitor->email);
    }

    public function test_privacy_and_powered_by_links_follow_the_settings(): void
    {
        $exhibition = Exhibition::factory()->create();
        $url = '/r/'.$exhibition->public_token;

        $this->get($url)->assertDontSee(__('public.privacy', [], 'ar'))->assertSee('Powered by Unisolva');

        app(Settings::class)->set('privacy_url', 'https://company.example.test/privacy');
        app(Settings::class)->set('show_powered_by', false);

        $this->get($url)->assertSee('https://company.example.test/privacy')->assertDontSee('Powered by Unisolva');
    }

    public function test_the_page_weight_is_under_40_kb_compressed(): void
    {
        $exhibition = Exhibition::factory()->create();
        $html = $this->get('/r/'.$exhibition->public_token)->getContent();

        $total = strlen(gzencode($html, 6))
            + strlen(gzencode((string) file_get_contents(public_path('assets/app.css')), 6))
            + strlen(gzencode((string) file_get_contents(public_path('assets/app.js')), 6));

        $this->assertLessThan(40 * 1024, $total);
        $this->assertStringContainsString(Asset::url('app.js'), $html);
    }
}
