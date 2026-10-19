<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Database\Factories;

use App\Models\Exhibition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Invented exhibitions for tests.
 *
 * @extends Factory<Exhibition>
 */
class ExhibitionFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-10 days', '+30 days');

        return [
            'name' => 'Sample Agri Fair '.fake()->unique()->numberBetween(1, 99999),
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => (clone $start)->modify('+2 days')->format('Y-m-d'),
            'is_open' => true,
            'default_locale' => 'ar',
            'created_by' => User::factory()->admin(),
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_open' => false]);
    }

    public function withPackage(string $noteEn = 'Your welcome gift is waiting at our stand.', string $noteAr = 'هديتك في انتظارك في جناحنا.'): static
    {
        return $this->state(fn () => ['offers_package' => true, 'package_note_en' => $noteEn, 'package_note_ar' => $noteAr]);
    }

    public function english(): static
    {
        return $this->state(fn () => ['default_locale' => 'en']);
    }
}
