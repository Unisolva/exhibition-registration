<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Settings;

use App\Models\Exhibition;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M11: an optional accent colour fills the thank-you page's gift box; the
 * text colour on it (dark ink or white) is chosen automatically.
 */
class AccentColourTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const BASE = ['org_name_en' => 'Sample Agri Company', 'org_name_ar' => 'شركة', 'brand_color' => '#1F4E79'];

    public function test_ink_is_dark_on_a_light_accent_and_white_on_a_dark_one(): void
    {
        $this->assertSame(Branding::INK_DARK, Branding::inkFor('#FEB71F')[0]);
        $this->assertSame(Branding::INK_LIGHT, Branding::inkFor('#2D7A1D')[0]);
        $this->assertGreaterThanOrEqual(4.5, Branding::inkFor('#FEB71F')[1]);
        $this->assertGreaterThanOrEqual(4.5, Branding::inkFor('#2D7A1D')[1]);
    }

    public function test_the_branding_page_saves_normalises_clears_and_refuses_a_colour_neither_ink_can_read_on(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/staff/settings/branding')->assertOk()->assertSee('Accent colour (optional)')
            ->assertSee('Fills the gift box on the thank-you page. Leave empty to keep the dashed brand-colour border.')
            ->assertDontSee('accent-preview', false);

        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'accent_color' => '#feb71f'])->assertSessionHasNoErrors();
        $this->assertSame('#FEB71F', app(Settings::class)->get('accent_color'));
        $this->actingAs($super)->get('/staff/settings/branding')
            ->assertSee('.accent-preview{background:#FEB71F;color:#1B1F24}', false)
            ->assertSee('Gift box preview: #FEB71F with dark text');

        // #777777 reads 4.48:1 with white and 3.9:1 with dark ink: refused, the ratio in the message.
        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'accent_color' => '#777777'])
            ->assertSessionHasErrors(['accent_color' => 'Neither dark nor white text reaches 4.5:1 on this colour; the better one has 4.48:1. Choose a lighter or darker colour.']);
        $this->assertSame('#FEB71F', app(Settings::class)->get('accent_color'));
        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'accent_color' => 'yellow'])->assertSessionHasErrors('accent_color');

        $this->actingAs($super)->put('/staff/settings/branding', [...self::BASE, 'accent_color' => ''])->assertSessionHasNoErrors();
        $this->assertNull(app(Settings::class)->get('accent_color'));
    }

    public function test_the_thank_you_page_gets_the_variables_only_when_an_accent_is_set(): void
    {
        $exhibition = Exhibition::factory()->withPackage()->create();
        $visitor = ['name' => 'Gift Visitor', 'phone' => '01001234567'];

        $this->submitPublicForm($exhibition, $visitor)->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        $html = $this->get('/r/'.$exhibition->public_token.'/thanks')->assertOk()->getContent();
        $this->assertStringContainsString('class="package-box"', $html);
        $this->assertStringNotContainsString('--accent', $html);
        $this->assertStringContainsString('<body class="public">', $html);

        app(Settings::class)->set('accent_color', '#2D7A1D');
        $html = $this->get('/r/'.$exhibition->public_token.'/thanks')->assertOk()->getContent();
        $this->assertStringContainsString(';--accent:#2D7A1D;--accent-ink:#FFFFFF}</style>', $html);
        $this->assertStringContainsString('<body class="public has-accent">', $html);
        $this->assertStringNotContainsString(' style="', $html);
    }
}
