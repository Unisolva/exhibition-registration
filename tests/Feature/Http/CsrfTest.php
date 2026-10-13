<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Http;

use App\Models\Exhibition;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Laravel 13's request-forgery check, which the test client normally skips:
 * a same-origin request passes on its Sec-Fetch-Site header (current
 * browsers), anything else needs the session's CSRF token. Pinned here because
 * Save & New's 419 handling relies on it.
 */
class CsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function entry(): array
    {
        return ['exhibition_id' => (string) Exhibition::factory()->create()->id, 'submission_token' => (string) Str::ulid(),
            'name' => 'Csrf Visitor', 'phone' => '01001234567', '_token' => 'not-the-session-token'];
    }

    public function test_a_cross_site_or_header_less_request_with_a_wrong_token_is_419(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->withHeaders(['Sec-Fetch-Site' => 'cross-site'])->postJson('/staff/registrations', $this->entry())->assertStatus(419);
        $this->actingAs($staff)->postJson('/staff/registrations', $this->entry())->assertStatus(419);
        $this->actingAs($staff)->withHeaders(['Sec-Fetch-Site' => 'same-site'])->postJson('/staff/registrations', $this->entry())->assertStatus(419);
    }

    public function test_a_same_origin_browser_request_passes_on_its_header(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff)->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->postJson('/staff/registrations', $this->entry())->assertCreated();
    }
}
