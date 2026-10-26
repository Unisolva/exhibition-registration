<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Settings;

use App\Models\Exhibition;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M11 v1.1: an optional "Gift text colour" for the thank-you page's line that
 * asks visitors to show the code for their gift. The box itself is unchanged.
 */
class GiftTextColourTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const BASE = ['org_name_en' => 'Sample Agri Company', 'org_name_ar' => 'شركة', 'brand_color' => '#1F4E79'];

    public function test_the_branding_page_saves_normalises_clears_and_refuses_a_light_colour(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/staff/settings/branding')->assertOk()->assertSee('Gift text colour (optional)')
            ->assertSee('Colour of the line that tells visitors to show the code for their gift. Leave empty for the normal text colour.')
            ->assertDontSee('gift-text-sample', false);

        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'gift_text_color' => '#2d7a1d'])->assertSessionHasNoErrors();
        $this->assertSame('#2D7A1D', app(Settings::class)->get('gift_text_color'));
        $html = $this->actingAs($super)->get('/staff/settings/branding')->getContent();
        $this->assertStringContainsString('.gift-text-sample{color:#2D7A1D}', $html);
        $this->assertStringContainsString('<p class="gift-text-sample">Show this code or the screenshot to our team to receive your gift.</p>', $html);
        $this->assertStringNotContainsString(' style="', $html);

        // #41AF2A on white is 2.8:1: refused, the ratio with two decimals.
        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'gift_text_color' => '#41AF2A'])
            ->assertSessionHasErrors(['gift_text_color' => 'This colour on white has a contrast of 2.84:1; it needs at least 4.5:1. Choose a darker colour.']);
        $this->assertSame('#2D7A1D', app(Settings::class)->get('gift_text_color'));
        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'gift_text_color' => 'green'])->assertSessionHasErrors('gift_text_color');

        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'gift_text_color' => ''])->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->get('gift_text_color'));
    }

    public function test_the_thank_you_page_gets_the_colour_only_when_set_and_never_an_accent(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $this->submitPublicForm($exhibition, ['name' => 'Gift Visitor', 'phone' => '01001234567'])->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        $thanks = '/r/'.$exhibition->public_token.'/thanks';

        $html = $this->get($thanks)->assertOk()->getContent();
        $this->assertStringContainsString('<body class="public">', $html);
        $this->assertStringNotContainsString('--gift-text', $html);

        // A stored accent from the withdrawn v1.0 build is ignored.
        app(Settings::class)->set('accent_color', '#FEB71F');
        app(Settings::class)->set('gift_text_color', '#2D7A1D');
        $html = $this->get($thanks)->assertOk()->getContent();
        $this->assertStringContainsString(':root{--brand:#1F4E79;--gift-text:#2D7A1D}</style>', $html);
        $this->assertStringContainsString('<body class="public has-gift-text">', $html);
        $this->assertStringContainsString('class="package-box"', $html);
        $this->assertStringNotContainsString('accent', $html);
        $this->assertStringNotContainsString(' style="', $html);
    }
}
