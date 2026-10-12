<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Data;

use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work order §4 (tables, InnoDB, utf8mb4_unicode_ci) and §11 (ProductSeeder is
 * idempotent, never overwrites admin changes, never creates users).
 */
class SchemaAndSeedTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'users', 'sessions', 'cache', 'cache_locks', 'exhibitions', 'field_definitions', 'regions',
        'visitors', 'visitor_edits', 'erp_connections', 'lead_syncs', 'erp_batches', 'erp_push_items',
        'settings', 'drain_runs', 'audit_events',
    ];

    public function test_every_table_exists_as_innodb_utf8mb4_unicode_ci(): void
    {
        $tables = collect(DB::select(
            'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_COLLATION AS collation
             FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        ))->keyBy('name');

        foreach (self::TABLES as $table) {
            $this->assertTrue($tables->has($table), "Table {$table} is missing.");
            $this->assertSame('InnoDB', $tables[$table]->engine, $table);
            $this->assertSame('utf8mb4_unicode_ci', $tables[$table]->collation, $table);
        }
    }

    public function test_arabic_text_round_trips(): void
    {
        DB::table('regions')->where(['country_code' => 'EG', 'code' => 'C'])->update(['name_ar' => 'القاهرة ٠١٢']);

        $this->assertSame('القاهرة ٠١٢', DB::table('regions')->where(['country_code' => 'EG', 'code' => 'C'])->value('name_ar'));
    }

    public function test_the_product_seed_has_system_fields_regions_and_settings_and_no_users(): void
    {
        $this->assertSame(
            ['name', 'phone', 'email', 'occupation', 'governorate'],
            DB::table('field_definitions')->orderBy('sort_order')->pluck('key')->all(),
        );
        $this->assertSame(5, DB::table('field_definitions')->where('is_system', true)->count());
        $this->assertSame('Governorate', DB::table('field_definitions')->where('key', 'governorate')->value('label_en'));
        $this->assertSame(27, DB::table('regions')->where('country_code', 'EG')->count());
        $this->assertSame(0, DB::table('regions')->whereColumn('code', '!=', 'erp_code')->count());
        $this->assertSame('"none"', DB::table('settings')->where('key', 'erp_mode')->value('value'));
        $this->assertSame('false', DB::table('settings')->where('key', 'erp_push_enabled')->value('value'));
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_reseeding_changes_nothing_an_admin_edited(): void
    {
        DB::table('field_definitions')->where('key', 'occupation')->update([
            'label_en' => 'Job', 'options' => json_encode([['value' => 'farmer', 'label_en' => 'Farmer', 'label_ar' => 'مزارع', 'is_active' => true]]),
            'sort_order' => 99, 'is_required' => true,
        ]);
        DB::table('settings')->where('key', 'retention_months')->update(['value' => '12']);

        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $occupation = DB::table('field_definitions')->where('key', 'occupation')->first();
        $this->assertSame('Job', $occupation->label_en);
        $this->assertSame(99, (int) $occupation->sort_order);
        $this->assertStringContainsString('farmer', $occupation->options);
        $this->assertSame('12', DB::table('settings')->where('key', 'retention_months')->value('value'));
        $this->assertSame(5, DB::table('field_definitions')->count());
        $this->assertSame(27, DB::table('regions')->count());
        $this->assertSame(0, DB::table('users')->count());
    }
}
