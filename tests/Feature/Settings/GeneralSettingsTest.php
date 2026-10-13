<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Settings;

use App\Models\Exhibition;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work order §7 "Settings" and §9 kill switches (the public one; the push
 * switch arrives with the ERP screens).
 */
class GeneralSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_kill_switch_stops_every_public_form_without_a_deploy(): void
    {
        $super = User::factory()->superAdmin()->create();
        $exhibition = Exhibition::factory()->create();

        $this->actingAs($super)->put('/staff/settings', [
            'default_country' => 'EG', 'display_timezone' => 'Africa/Cairo', 'retention_months' => '24',
        ])->assertRedirect(route('settings.general'));

        app(Settings::class)->forget();
        $this->assertFalse(app(Settings::class)->get('public_registration_enabled'));
        $this->get('/r/'.$exhibition->public_token)->assertOk()->assertDontSee('name="submission_token"', false);
        $this->assertDatabaseHas('audit_events', ['action' => 'settings_updated']);
    }

    public function test_values_are_validated(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->put('/staff/settings', [
            'default_country' => 'XX', 'display_timezone' => 'UTC+2', 'retention_months' => '0', 'public_registration_enabled' => '1',
        ])->assertSessionHasErrors(['default_country', 'display_timezone', 'retention_months']);

        $this->actingAs($super)->put('/staff/settings', [
            'default_country' => 'EG', 'display_timezone' => 'Europe/London', 'retention_months' => '12', 'public_registration_enabled' => '1',
        ])->assertSessionHasNoErrors();

        app(Settings::class)->forget();
        $this->assertSame('Europe/London', app(Settings::class)->get('display_timezone'));
        $this->assertSame(12, app(Settings::class)->get('retention_months'));
    }
}
