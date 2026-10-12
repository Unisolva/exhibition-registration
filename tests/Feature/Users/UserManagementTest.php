<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work order §7 "Users" and the user rules of §10.6.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_staff_and_admins_with_a_one_time_password_shown_once(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['staff', 'admin'] as $role) {
            $response = $this->actingAs($admin)->post('/staff/users', [
                'name' => "New {$role}", 'email' => "New.{$role}@Example.test", 'role' => $role,
            ])->assertOk()->assertSee('shown only now');

            $created = User::query()->where('email', "new.{$role}@example.test")->firstOrFail();
            $this->assertSame($role, $created->role->value);
            $this->assertTrue($created->must_change_password);

            preg_match('/<p class="secret">([^<]+)<\/p>/', $response->getContent(), $m);
            $this->assertSame(16, strlen($m[1]));
            $this->assertTrue(password_verify($m[1], $created->password));

            // Not kept anywhere: not in any session payload and not on the next page.
            foreach (DB::table('sessions')->pluck('payload') as $payload) {
                $this->assertStringNotContainsString($m[1], base64_decode($payload));
            }
            $this->get('/staff/users')->assertDontSee($m[1]);
            $this->assertDatabaseHas('audit_events', ['action' => 'user_created', 'target_id' => $created->id, 'user_id' => $admin->id]);
            $this->assertStringNotContainsString($m[1], (string) DB::table('audit_events')->where('target_id', $created->id)->value('meta'));
        }
    }

    public function test_admin_cannot_assign_the_super_admin_role(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();

        $this->actingAs($admin)->post('/staff/users', [
            'name' => 'X', 'email' => 'x@example.test', 'role' => 'super_admin',
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);

        $this->actingAs($admin)->put("/staff/users/{$staff->id}", [
            'name' => $staff->name, 'email' => $staff->email, 'role' => 'super_admin', 'is_active' => '1',
        ])->assertForbidden();
        $this->assertSame(Role::Staff, $staff->fresh()->role);
    }

    public function test_admin_gets_403_on_every_action_against_a_super_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get("/staff/users/{$super->id}/edit")->assertForbidden();
        $this->actingAs($admin)->put("/staff/users/{$super->id}", [
            'name' => 'Changed', 'email' => $super->email, 'role' => 'super_admin', 'is_active' => '0',
        ])->assertForbidden();
        $this->actingAs($admin)->post("/staff/users/{$super->id}/reset-password")->assertForbidden();

        $this->assertSame($super->name, $super->fresh()->name);
        $this->assertTrue($super->fresh()->is_active);
        $this->actingAs($admin)->get('/staff/users')->assertOk()->assertDontSee($super->email);
    }

    public function test_admin_manages_other_admins_but_not_their_own_role(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();

        $this->actingAs($admin)->put("/staff/users/{$other->id}", [
            'name' => $other->name, 'email' => $other->email, 'role' => 'staff', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame(Role::Staff, $other->fresh()->role);

        $this->actingAs($admin)->put("/staff/users/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'staff', 'is_active' => '1',
        ])->assertForbidden();
        $this->assertSame(Role::Admin, $admin->fresh()->role);

        // Editing their own name is fine.
        $this->actingAs($admin)->put("/staff/users/{$admin->id}", [
            'name' => 'Renamed Admin', 'email' => $admin->email, 'role' => 'admin', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame('Renamed Admin', $admin->fresh()->name);
    }

    public function test_the_last_active_super_admin_cannot_be_demoted_or_deactivated(): void
    {
        $super = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->inactive()->create();

        $this->actingAs($super)->put("/staff/users/{$super->id}", [
            'name' => $super->name, 'email' => $super->email, 'role' => 'admin', 'is_active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertSame(Role::SuperAdmin, $super->fresh()->role);

        // With a second active super admin, demotion is allowed.
        $second = User::factory()->superAdmin()->create();
        $this->actingAs($second)->put("/staff/users/{$super->id}", [
            'name' => $super->name, 'email' => $super->email, 'role' => 'admin', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertSame(Role::Admin, $super->fresh()->role);

        // Now $second is the last one: it cannot be deactivated (by anyone) or demote itself.
        $this->actingAs($second)->put("/staff/users/{$second->id}", [
            'name' => $second->name, 'email' => $second->email, 'role' => 'admin', 'is_active' => '1',
        ])->assertSessionHasErrors('role');
        $this->assertSame(Role::SuperAdmin, $second->fresh()->role);
    }

    public function test_nobody_can_deactivate_themselves(): void
    {
        foreach ([User::factory()->superAdmin()->create(), User::factory()->admin()->create()] as $user) {
            User::factory()->superAdmin()->create();

            $this->actingAs($user)->put("/staff/users/{$user->id}", [
                'name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'is_active' => '0',
            ])->assertSessionHasErrors('is_active');
            $this->assertTrue($user->fresh()->is_active);
        }
    }

    public function test_reset_password_replaces_the_password_and_requires_a_change(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $oldHash = $staff->password;

        $this->actingAs($admin)->post("/staff/users/{$staff->id}/reset-password")->assertOk()->assertSee('Password reset');

        $this->assertNotSame($oldHash, $staff->fresh()->password);
        $this->assertTrue($staff->fresh()->must_change_password);
        $this->assertDatabaseHas('audit_events', ['action' => 'password_reset', 'target_id' => $staff->id, 'user_id' => $admin->id]);

        // Nobody resets their own password here; they use the password screen.
        $this->actingAs($admin)->post("/staff/users/{$admin->id}/reset-password")->assertForbidden();
    }

    public function test_emails_are_unique_ignoring_case(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($admin)->post('/staff/users', [
            'name' => 'Dup', 'email' => 'TAKEN@example.test', 'role' => 'staff',
        ])->assertSessionHasErrors('email');
    }
}
