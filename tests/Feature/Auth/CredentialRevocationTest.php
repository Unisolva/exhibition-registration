<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work order §10.6 (deactivated session rejected) and §10.7: a password change
 * or deactivation ends sessions and invalidates a real remember-me cookie.
 */
class CredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivation_ends_sessions_and_remember_cookies(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $device = $this->browserLogin($staff, remember: true);
        $rememberOnly = [$this->rememberCookieName() => $device[$this->rememberCookieName()]];

        // Both credentials work before the change.
        $this->getWithCookies('/staff', $device)->assertOk();
        $this->getWithCookies('/staff', $rememberOnly)->assertOk();
        $this->assertGreaterThan(0, DB::table('sessions')->where('user_id', $staff->id)->count());

        $this->newProcess();
        $this->actingAs($admin)->put("/staff/users/{$staff->id}", [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'staff', 'is_active' => '0',
        ])->assertRedirect(route('users.index'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $staff->id)->count());
        $this->getWithCookies('/staff', $device)->assertRedirect(route('login'));
        $this->getWithCookies('/staff', $rememberOnly)->assertRedirect(route('login'));
        $this->assertDatabaseHas('audit_events', ['action' => 'user_deactivated', 'target_id' => $staff->id, 'user_id' => $admin->id]);
    }

    public function test_a_deactivated_users_open_session_is_rejected_on_the_next_request(): void
    {
        $staff = User::factory()->staff()->create();
        $device = $this->browserLogin($staff);
        $this->getWithCookies('/staff', $device)->assertOk();

        // Deactivated directly in the database: the session row still exists, the active check still stops it.
        DB::table('users')->where('id', $staff->id)->update(['is_active' => false]);

        $this->getWithCookies('/staff', $device)->assertRedirect(route('login'));
        $this->getWithCookies('/staff', $device)->assertRedirect(route('login'));
    }

    public function test_own_password_change_keeps_this_device_and_ends_all_others(): void
    {
        $user = User::factory()->create();

        $other = $this->browserLogin($user, remember: true);
        $otherRemember = [$this->rememberCookieName() => $other[$this->rememberCookieName()]];
        $current = $this->browserLogin($user, remember: true);

        $this->newProcess();
        $response = $this->withCookies($current)->put('/staff/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'brand-new-password-1',
            'password_confirmation' => 'brand-new-password-1',
        ])->assertRedirect(route('staff.home'));

        // This device continues with the cookies it was just given (new session ID, new remember token).
        $updated = array_merge($current, $this->cookiesFrom($response));
        $this->assertNotSame($current[config('session.cookie')], $updated[config('session.cookie')]);
        $this->getWithCookies('/staff', $updated)->assertOk();
        $this->getWithCookies('/staff', [$this->rememberCookieName() => $updated[$this->rememberCookieName()]])->assertOk();

        // The other device's session and remember cookie are dead.
        $this->getWithCookies('/staff', $other)->assertRedirect(route('login'));
        $this->getWithCookies('/staff', $otherRemember)->assertRedirect(route('login'));

        // The old session ID of this device is gone too.
        $this->assertSame(0, DB::table('sessions')->where('id', $current[config('session.cookie')])->count());
    }

    public function test_an_admin_password_reset_ends_every_session_of_that_user(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $device = $this->browserLogin($staff, remember: true);

        $this->newProcess();
        $this->actingAs($admin)->post("/staff/users/{$staff->id}/reset-password")->assertOk();

        $this->getWithCookies('/staff', $device)->assertRedirect(route('login'));
        $this->getWithCookies('/staff', [$this->rememberCookieName() => $device[$this->rememberCookieName()]])
            ->assertRedirect(route('login'));
        $this->assertTrue($staff->fresh()->must_change_password);
    }
}
