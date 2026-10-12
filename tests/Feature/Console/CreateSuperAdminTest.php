<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_super_admin_who_must_change_the_printed_password(): void
    {
        $this->artisan('user:create-super-admin', ['--name' => 'First Admin', '--email' => 'First@Example.test'])
            ->expectsOutputToContain('One-time password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'first@example.test')->firstOrFail();
        $this->assertSame(Role::SuperAdmin, $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'user_created', 'target_id' => $user->id]);
    }

    public function test_missing_options_or_a_taken_email_fail_without_prompting(): void
    {
        $this->artisan('user:create-super-admin')->assertFailed();
        $this->artisan('user:create-super-admin', ['--name' => 'X'])->assertFailed();

        User::factory()->create(['email' => 'taken@example.test']);
        $this->artisan('user:create-super-admin', ['--name' => 'X', '--email' => 'taken@example.test'])->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
