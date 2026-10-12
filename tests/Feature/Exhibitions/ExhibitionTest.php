<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Exhibitions;

use App\Models\Exhibition;
use App\Models\User;
use App\Support\Crockford;
use App\Support\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work order §7 "Exhibitions" and §4 (token rules).
 */
class ExhibitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_creates_an_exhibition_with_a_ten_character_crockford_token(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/staff/exhibitions', [
            'name' => 'Upper Egypt Agri Days', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-05',
        ])->assertRedirect();

        $exhibition = Exhibition::query()->sole();
        $this->assertMatchesRegularExpression('/^['.Crockford::ALPHABET.']{10}$/', $exhibition->public_token);
        $this->assertTrue($exhibition->is_open);
        $this->assertSame($admin->id, $exhibition->created_by);
        $this->assertDatabaseHas('audit_events', ['action' => 'exhibition_created', 'target_id' => $exhibition->id, 'user_id' => $admin->id]);
    }

    public function test_tokens_are_random_and_valid(): void
    {
        $tokens = [];
        for ($i = 0; $i < 300; $i++) {
            $token = Crockford::random(10);
            $this->assertMatchesRegularExpression('/^['.Crockford::ALPHABET.']{10}$/', $token);
            $tokens[$token] = true;
        }
        $this->assertCount(300, $tokens);
    }

    public function test_renaming_never_changes_the_token(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create();
        $token = $exhibition->public_token;

        $this->actingAs($admin)->put("/staff/exhibitions/{$exhibition->id}", [
            'name' => 'Renamed Fair', 'starts_on' => '2026-12-01', 'ends_on' => '',
        ])->assertRedirect();

        $exhibition->refresh();
        $this->assertSame('Renamed Fair', $exhibition->name);
        $this->assertNull($exhibition->ends_on);
        $this->assertSame($token, $exhibition->public_token);
        $this->assertDatabaseHas('audit_events', ['action' => 'exhibition_updated', 'target_id' => $exhibition->id]);
    }

    public function test_dates_are_validated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/staff/exhibitions', ['name' => 'X', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-03'])
            ->assertSessionHasErrors('ends_on');
        $this->actingAs($admin)->post('/staff/exhibitions', ['name' => '', 'starts_on' => 'tomorrow'])
            ->assertSessionHasErrors(['name', 'starts_on']);
        $this->assertSame(0, Exhibition::query()->count());
    }

    public function test_open_and_close_are_audited_and_change_the_public_form(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($admin)->post("/staff/exhibitions/{$exhibition->id}/close")->assertRedirect();
        $this->assertFalse($exhibition->fresh()->is_open);
        $this->assertDatabaseHas('audit_events', ['action' => 'exhibition_closed', 'target_id' => $exhibition->id]);
        $this->get('/r/'.$exhibition->public_token)->assertDontSee('name="submission_token"', false);

        $this->actingAs($admin)->post("/staff/exhibitions/{$exhibition->id}/open")->assertRedirect();
        $this->assertTrue($exhibition->fresh()->is_open);
        $this->assertDatabaseHas('audit_events', ['action' => 'exhibition_opened', 'target_id' => $exhibition->id]);
        $this->get('/r/'.$exhibition->public_token)->assertSee('name="submission_token"', false);
    }

    public function test_the_page_shows_the_url_and_an_inline_svg_qr_and_offers_a_download_and_a_poster(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create(['name' => 'QR Fair']);
        $url = 'http://localhost/r/'.$exhibition->public_token;

        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}")
            ->assertOk()
            ->assertSee($url)
            ->assertSee('<svg', false);

        $download = $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}/qr.svg")->assertOk();
        $this->assertSame('image/svg+xml', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $svg = $download->getContent();
        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertStringNotContainsString('style=', $svg);

        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}/poster")
            ->assertOk()
            ->assertSee('QR Fair')
            ->assertSee('localhost/r/'.$exhibition->public_token)
            ->assertSee('<svg', false);
    }

    public function test_the_qr_encodes_only_the_url(): void
    {
        $exhibition = Exhibition::factory()->create();

        $this->assertSame('http://localhost/r/'.$exhibition->public_token, $exhibition->publicUrl());
        $this->assertSame(
            QrCode::svg($exhibition->publicUrl()),
            $this->actingAs(User::factory()->admin()->create())->get("/staff/exhibitions/{$exhibition->id}/qr.svg")->getContent(),
        );
    }

    public function test_lowercase_and_look_alike_letters_in_a_typed_token_still_find_the_exhibition(): void
    {
        $exhibition = Exhibition::factory()->create(['public_token' => 'AB10CD0EF1']);

        $this->get('/r/ab10cd0ef1')->assertOk();
        $this->get('/r/ABlOCDoEFI')->assertOk();
    }
}
