<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_user_signs_in_and_is_audited(): void
    {
        $user = User::factory()->staff()->create();

        $this->post('/staff/login', ['email' => strtoupper($user->email), 'password' => UserFactory::PASSWORD])
            ->assertRedirect(route('staff.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'login', 'user_id' => $user->id, 'outcome' => 'ok']);
    }

    public function test_wrong_password_unknown_email_and_inactive_user_get_the_same_answer(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $attempts = [
            [$active->email, 'wrong-password'],
            ['nobody@example.test', UserFactory::PASSWORD],
            [$inactive->email, UserFactory::PASSWORD],
        ];

        foreach ($attempts as [$email, $password]) {
            $this->newProcess();
            $this->from('/staff/login')->post('/staff/login', ['email' => $email, 'password' => $password])
                ->assertRedirect('/staff/login')
                ->assertSessionHasErrors(['email' => 'These details do not match an active account.']);
            $this->assertGuest();
        }

        $this->assertSame(3, DB::table('audit_events')->where('action', 'login_failed')->count());
    }

    public function test_a_one_time_password_forces_the_change_screen_first(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $this->post('/staff/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD]);

        $this->get('/staff')->assertRedirect(route('password.edit'));
        $this->get('/staff/users')->assertRedirect(route('password.edit'));
        $this->get('/staff/password')->assertOk()->assertSee('one-time password');

        $this->put('/staff/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'a-new-long-password',
            'password_confirmation' => 'a-new-long-password',
        ])->assertRedirect(route('staff.home'));

        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/staff/entry')->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'password_changed', 'user_id' => $user->id]);
    }

    public function test_five_attempts_per_email_and_ip_then_429(): void
    {
        $user = User::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $this->newProcess();
            $this->post('/staff/login', ['email' => $user->email, 'password' => 'wrong'])->assertRedirect();
        }

        $this->newProcess();
        $this->post('/staff/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertStatus(429);

        // Forged forwarding headers do not create a fresh client address.
        $this->newProcess();
        $this->withHeaders(['X-Forwarded-For' => '198.51.100.7', 'CF-Connecting-IP' => '198.51.100.7'])
            ->post('/staff/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertStatus(429);

        // Another address is not affected.
        $this->newProcess();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/staff/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
            ->assertRedirect(route('staff.home'));
    }

    public function test_thirty_attempts_per_ip_across_emails_then_429(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->newProcess();
            $this->post('/staff/login', ['email' => "person{$i}@example.test", 'password' => 'wrong'])->assertRedirect();
        }

        $this->newProcess();
        $this->post('/staff/login', ['email' => 'person31@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_sign_out_ends_the_session_and_is_audited(): void
    {
        $user = User::factory()->create();
        $cookies = $this->browserLogin($user);

        $this->newProcess();
        $this->withCookies($cookies)->post('/staff/logout')->assertRedirect(route('login'));
        $this->assertDatabaseHas('audit_events', ['action' => 'logout', 'user_id' => $user->id]);

        $this->getWithCookies('/staff/entry', $cookies)->assertRedirect(route('login'));
    }

    public function test_signed_in_users_are_sent_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/staff/login')->assertRedirect(route('staff.home'));
    }
}
