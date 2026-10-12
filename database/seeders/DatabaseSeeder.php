<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Product data only. No users and no sample visitors.
     */
    public function run(): void
    {
        $this->call(ProductSeeder::class);
    }
}
