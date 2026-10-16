<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M8 CO-1 (v2.9 §10.5 as amended): there is no fill-time check and no
 * honeypot, so every valid public submission is saved. This file names the
 * removed fields on purpose and is excluded by name from the CO-1 grep.
 */
class SpamCheckRemovedTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Quick Autofill', 'phone' => '01001234567'];

    public function test_a_submission_posted_immediately_after_the_form_renders_is_saved(): void
    {
        $exhibition = Exhibition::factory()->create();

        // No pause between rendering and posting, as with one-tap autofill.
        $this->submitPublicForm($exhibition, self::VISITOR)
            ->assertStatus(303)
            ->assertRedirect('/r/'.$exhibition->public_token.'/thanks');

        $this->assertSame(1, Visitor::query()->count());
        $this->assertSame('Quick Autofill', Visitor::query()->sole()->name);
    }

    public function test_the_rendered_form_has_no_render_time_and_no_honeypot_input(): void
    {
        $html = $this->get('/r/'.Exhibition::factory()->create()->public_token)->assertOk()->getContent();

        $this->assertStringNotContainsString('rendered_at', $html);
        $this->assertStringNotContainsString('name="website"', $html);
        $this->assertStringNotContainsString('class="hp"', $html);
    }

    public function test_website_is_now_an_allowed_custom_field_key(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->post('/staff/settings/fields', [
            'key' => 'website', 'type' => 'text', 'label_en' => 'Website', 'label_ar' => 'الموقع',
            'is_active' => '1', 'send_to_erp' => '1', 'sort_order' => '60',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('field_definitions', ['key' => 'website', 'is_system' => false]);
    }
}
