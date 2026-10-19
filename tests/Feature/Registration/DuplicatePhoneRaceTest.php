<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Models\Exhibition;
use App\Models\Visitor;
use App\Registration\PhoneLock;
use Database\Seeders\ProductSeeder;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * The lock (M9 CO-7): two processes, a committed database, as ConcurrencyTest.
 */
class DuplicatePhoneRaceTest extends TestCase
{
    use PublicForm;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => ProductSeeder::class, '--force' => true]);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--seeder' => ProductSeeder::class, '--force' => true]);
        parent::tearDown();
    }

    private function child(Exhibition $exhibition, int $hold): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/race-public-phone.php'), (string) $exhibition->id, '+201001234567', (string) $hold], base_path(), null, null, 60);
        $process->start();
        $deadline = microtime(true) + 30;
        while (! str_contains($process->getOutput(), 'locked') && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertStringContainsString('locked', $process->getOutput(), $process->getErrorOutput());

        return $process;
    }

    public function test_a_simultaneous_second_registration_waits_for_the_first_and_is_refused(): void
    {
        $exhibition = Exhibition::factory()->english()->create();
        $hidden = $this->openPublicForm($exhibition);
        $child = $this->child($exhibition, 2);

        // The first registration holds the lock and has not inserted yet: without the lock this one would be saved too.
        $started = microtime(true);
        $this->submitPublicForm($exhibition, ['name' => 'Second Of Two', 'phone' => '01001234567'], $hidden)
            ->assertStatus(422)->assertSee('This number is already registered');
        $waited = microtime(true) - $started;
        $child->wait();

        $this->assertStringContainsString('inserted', $child->getOutput());
        $this->assertGreaterThan(0.8, $waited, 'The second registration waited for the first.');
        $this->assertSame(['First Of Two'], Visitor::query()->pluck('name')->all());
    }

    public function test_a_lock_timeout_gives_the_friendly_try_again_page_with_values_kept(): void
    {
        $exhibition = Exhibition::factory()->english()->create();
        $hidden = $this->openPublicForm($exhibition);
        $child = $this->child($exhibition, PhoneLock::TIMEOUT_SECONDS + 3);

        $this->submitPublicForm($exhibition, ['name' => 'Kept Name', 'phone' => '01001234567'], $hidden)
            ->assertStatus(429)
            ->assertSee(__('public.error.form_expired', [], 'en'))
            ->assertSee('value="Kept Name"', false);
        $child->wait();
        $this->assertSame(['First Of Two'], Visitor::query()->pluck('name')->all());
    }
}
