<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Settings;

use App\Models\Exhibition;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Work order §1a branding and §10.14.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    /** A valid 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const CLEAN_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><linearGradient id="g"><stop offset="0" stop-color="#060"/></linearGradient></defs><rect width="10" height="10" fill="url(#g)"/></svg>';

    private function save(array $fields, ?UploadedFile $logo = null)
    {
        $super = User::factory()->superAdmin()->create();

        return $this->actingAs($super)->put('/staff/settings/branding', [
            'org_name_en' => 'Sample Company', 'org_name_ar' => 'شركة تجريبية', 'brand_color' => '#1F4E79',
            'show_powered_by' => '1', ...$fields, ...($logo ? ['logo' => $logo] : []),
        ]);
    }

    public function test_names_colour_and_switches_are_saved_and_audited(): void
    {
        $this->save(['brand_color' => '#14532d', 'privacy_url' => 'https://company.example.test/privacy'])
            ->assertRedirect(route('settings.branding'))->assertSessionHasNoErrors();

        $settings = app(Settings::class);
        $settings->forget();
        $this->assertSame('Sample Company', $settings->get('org_name_en'));
        $this->assertSame('#14532D', $settings->get('brand_color'));
        $this->assertSame('https://company.example.test/privacy', $settings->get('privacy_url'));
        $this->assertDatabaseHas('audit_events', ['action' => 'settings_updated']);
    }

    public function test_a_low_contrast_or_malformed_colour_is_refused(): void
    {
        $this->save(['brand_color' => '#FFFF00'])->assertSessionHasErrors('brand_color');
        $this->save(['brand_color' => '#7f7f7f'])->assertSessionHasErrors('brand_color');
        $this->save(['brand_color' => 'red'])->assertSessionHasErrors('brand_color');

        $this->assertLessThan(4.5, Branding::contrastWithWhite('#7F7F7F'));
        $this->assertGreaterThanOrEqual(4.5, Branding::contrastWithWhite('#1F4E79'));
    }

    public function test_a_png_logo_is_stored_and_served_by_a_hashed_cacheable_url(): void
    {
        $bytes = base64_decode(self::PNG);
        $this->save([], UploadedFile::fake()->createWithContent('logo.png', $bytes))->assertSessionHasNoErrors();

        $url = app(Branding::class)->logoUrl();
        $this->assertSame('/logo/'.substr(hash('sha256', $bytes), 0, 16).'.png', $url);

        $response = $this->get($url)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertSame($bytes, $response->getContent());

        $this->get('/logo/0000000000000000.png')->assertNotFound();
        $this->get(str_replace('.png', '.svg', $url))->assertNotFound();

        $exhibition = Exhibition::factory()->create();
        $this->get('/r/'.$exhibition->public_token)->assertSee('src="'.$url.'"', false);
    }

    public function test_a_clean_svg_logo_is_accepted_and_served_sandboxed(): void
    {
        $this->save([], UploadedFile::fake()->createWithContent('logo.svg', self::CLEAN_SVG))->assertSessionHasNoErrors();

        $response = $this->get(app(Branding::class)->logoUrl())->assertOk();
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_logo_over_100_kb_is_rejected(): void
    {
        $big = base64_decode(self::PNG).str_repeat("\0", 102400);

        $this->save([], UploadedFile::fake()->createWithContent('logo.png', $big))->assertSessionHasErrors('logo');
        $this->assertNull(app(Branding::class)->logoUrl());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSvgs(): array
    {
        $open = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">';

        return [
            'script element' => [$open.'<script>alert(1)</script></svg>'],
            'event attribute' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>'],
            'event on a child' => [$open.'<rect onclick="alert(1)"/></svg>'],
            'external image' => [$open.'<image href="https://tracker.example/x.png"/></svg>'],
            'external xlink' => [$open.'<use xlink:href="https://evil.example/s.svg#a"/></svg>'],
            'javascript link' => [$open.'<a href="javascript:alert(1)"><rect/></a></svg>'],
            'foreignObject' => [$open.'<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject></svg>'],
            'style import' => [$open.'<style>@import url(https://evil.example/a.css);</style></svg>'],
            'style url' => [$open.'<rect style="fill:url(https://evil.example/p)"/></svg>'],
            'fill url' => [$open.'<rect fill="url(https://evil.example/p#g)"/></svg>'],
            'entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>'],
            'not svg' => ['<html><body>hello</body></html>'],
        ];
    }

    #[DataProvider('unsafeSvgs')]
    public function test_an_unsafe_svg_is_rejected(string $svg): void
    {
        $this->save([], UploadedFile::fake()->createWithContent('logo.svg', $svg))->assertSessionHasErrors('logo');
        $this->assertNull(app(Branding::class)->logoUrl());
    }

    public function test_the_logo_can_be_removed(): void
    {
        $this->save([], UploadedFile::fake()->createWithContent('logo.png', base64_decode(self::PNG)));
        $this->assertNotNull(app(Branding::class)->logoUrl());

        $this->save(['remove_logo' => '1']);
        app(Settings::class)->forget();
        $this->assertNull(app(Branding::class)->logoUrl());
    }
}
