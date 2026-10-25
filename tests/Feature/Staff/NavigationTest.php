<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M8 CO-13: the current page is marked and the forced password change page
 * shows no navigation. M10 CO-2: the Tabler sidebar groups the links as
 * Event, Manage and Settings; phones get Save & New and Packages beside the menu.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_current_page_is_marked(): void
    {
        $html = $this->actingAs(User::factory()->admin()->create())->get('/staff/visitors')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#<li class="nav-item active">\s*<a class="nav-link" href="[^"]+/staff/visitors"\s+aria-current="page"\s*>#', $html);
    }

    public function test_the_sidebar_groups_the_links_and_settings_pages_are_in_it(): void
    {
        $super = User::factory()->superAdmin()->create();
        $html = $this->actingAs($super)->get('/staff/visitors')->assertOk()->getContent();
        $this->assertStringContainsString('<nav class="staff-menu" aria-label="Main">', $html);
        foreach (['Event' => ['Save &amp; New', 'Packages', 'Visitors'], 'Manage' => ['Exhibitions', 'Users', 'Trash'],
            'Settings' => ['Settings', 'Branding', 'Form fields', 'ERP', 'System']] as $group => $labels) {
            $this->assertStringContainsString('<h2 class="nav-section-title">'.$group.'</h2>', $html);
            $this->actingAs($super)->get('/staff/visitors')->assertSeeInOrder(array_map(fn ($l) => '<span class="nav-link-title">'.$l.'</span>', $labels), false);
        }
        // The phone shortcuts beside the menu button.
        $this->assertMatchesRegularExpression('#<div class="staff-shortcuts d-lg-none">\s*<a class="btn btn-primary" href="[^"]+/staff/entry"\s*>Save &amp; New</a>\s*<a class="btn btn-primary" href="[^"]+/staff/packages"\s*>Packages</a>#', $html);
        $this->assertStringContainsString('aria-controls="staff-menu" aria-expanded="false"', $html);

        foreach (['/staff/settings', '/staff/settings/branding', '/staff/settings/fields/create', '/staff/erp', '/staff/system'] as $path) {
            $this->actingAs($super)->get($path)->assertOk()->assertSee('<li class="nav-item active">', false);
        }

        // Staff: no Manage or Settings group, "My entries".
        $staff = $this->actingAs(User::factory()->create())->get('/staff/visitors')->assertOk()->getContent();
        $this->assertStringNotContainsString('>Manage</h2>', $staff);
        $this->assertStringNotContainsString('>Settings</h2>', $staff);
        $this->assertStringContainsString('<span class="nav-link-title">My entries</span>', $staff);
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
