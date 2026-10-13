<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Database\Factories;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Invented visitors for tests. Phone numbers are unique Egyptian mobile
 * numbers in the 0100 range.
 *
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    public function definition(): array
    {
        $national = '0100'.str_pad((string) fake()->unique()->numberBetween(0, 9999999), 7, '0', STR_PAD_LEFT);
        $e164 = '+20'.substr($national, 1);

        return [
            'exhibition_id' => Exhibition::factory(),
            'name' => fake()->firstName().' '.fake()->lastName(),
            'phone_raw' => $national,
            'phone_e164' => $e164,
            'phone_key' => $e164,
            'phone_valid' => true,
            'email' => null,
            'occupation' => null,
            'region_code' => 'C',
            'region_label' => 'Cairo',
            'extra' => [],
            'source' => 'public',
            'entered_by' => null,
            'submission_token' => (string) Str::ulid(),
            'payload_hash' => hash('sha256', Str::random(20)),
            'locale' => 'ar',
            'request_id' => (string) Str::ulid(),
        ];
    }

    public function enteredBy(User $user): static
    {
        return $this->state(fn () => ['source' => 'staff', 'entered_by' => $user->id, 'locale' => 'en']);
    }

    public function at(Exhibition $exhibition): static
    {
        return $this->state(fn () => ['exhibition_id' => $exhibition->id]);
    }

    public function trashed(?User $by = null, int $daysAgo = 1): static
    {
        return $this->state(fn () => ['trashed_at' => now()->subDays($daysAgo), 'trashed_by' => $by?->id]);
    }
}
