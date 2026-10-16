<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M8 CO-13: the current page is marked, settings pages share one sub-nav, and
 * the forced password change page shows no navigation.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_page_is_marked_in_both_navigations(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get('/staff/visitors')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'aria-current="page" >Visitors</a>') + substr_count($html, 'aria-current="page">Visitors</a>'));
        $this->assertStringNotContainsString('aria-current="page" >Exhibitions', $html);
    }

    public function test_every_settings_page_has_the_settings_sub_nav(): void
    {
        $super = User::factory()->superAdmin()->create();
        foreach (['/staff/settings', '/staff/settings/branding', '/staff/settings/fields', '/staff/settings/fields/create', '/staff/erp', '/staff/system'] as $path) {
            $this->actingAs($super)->get($path)->assertOk()
                ->assertSee('<nav class="subnav" aria-label="Settings">', false)
                ->assertSeeInOrder(['>Settings</a>', '>Branding</a>', '>Form fields</a>', '>ERP</a>', '>System</a>'], false);
        }
        $this->actingAs($super)->get('/staff/visitors')->assertDontSee('class="subnav"', false);
    }

    public function test_the_forced_password_page_shows_no_navigation_but_sign_out(): void
    {
        $user = User::factory()->mustChangePassword()->create();

        $this->actingAs($user)->get('/staff/password')->assertOk()
            ->assertDontSee('aria-label="Main"', false)
            ->assertDontSee('>Save &amp; New</a>', false)
            ->assertSee('Sign out');
    }
}
