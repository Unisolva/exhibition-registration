<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Database\Seeders;

use App\Support\Settings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Run on every deploy (work order §11): idempotent upserts only. Adds missing
 * system fields, region data and settings; never overwrites anything an admin
 * changed (labels, options, order, settings) and never creates users.
 */
class ProductSeeder extends Seeder
{
    public function run(Settings $settings): void
    {
        $this->seedSettings($settings);
        $this->seedRegions();
        $this->seedSystemFields($settings);
    }

    private function seedSettings(Settings $settings): void
    {
        foreach (Settings::DEFAULTS as $key => $default) {
            if (! $settings->has($key)) {
                $settings->set($key, $key === 'default_country' ? config('exhibition.default_country') : $default);
            }
        }
    }

    private function seedRegions(): void
    {
        foreach (glob(database_path('data/regions/*.php')) as $file) {
            $data = require $file;
            $rows = [];
            foreach (array_values($data['regions']) as $index => $region) {
                $rows[] = [
                    'country_code' => $data['country_code'],
                    'code' => $region['code'],
                    'name_en' => $region['name_en'],
                    'name_ar' => $region['name_ar'],
                    'erp_code' => $region['erp_code'],
                    'sort_order' => $index + 1,
                ];
            }

            // Region data belongs to the product, so a newer data file wins.
            DB::table('regions')->upsert($rows, ['country_code', 'code'], ['name_en', 'name_ar', 'erp_code', 'sort_order']);
        }
    }

    private function seedSystemFields(Settings $settings): void
    {
        $country = (string) $settings->get('default_country');
        $regionFile = database_path("data/regions/{$country}.php");
        $regionData = is_file($regionFile) ? require $regionFile : [];

        $fields = [
            ['key' => 'name', 'type' => 'text', 'label_en' => 'Name', 'label_ar' => 'الاسم', 'is_required' => true],
            ['key' => 'phone', 'type' => 'phone', 'label_en' => 'Phone', 'label_ar' => 'رقم الهاتف', 'is_required' => true],
            ['key' => 'email', 'type' => 'email', 'label_en' => 'Email', 'label_ar' => 'البريد الإلكتروني', 'is_required' => false],
            ['key' => 'occupation', 'type' => 'select', 'label_en' => 'Occupation', 'label_ar' => 'المهنة', 'is_required' => false],
            [
                'key' => 'governorate', 'type' => 'region',
                'label_en' => $regionData['field_label_en'] ?? 'Region',
                'label_ar' => $regionData['field_label_ar'] ?? 'المنطقة',
                'is_required' => false,
            ],
        ];

        foreach ($fields as $order => $field) {
            // insertOrIgnore: an existing row keeps whatever an admin set.
            DB::table('field_definitions')->insertOrIgnore([
                ...$field,
                'is_system' => true,
                'options' => $field['type'] === 'select' ? '[]' : null,
                'is_active' => true,
                'sort_order' => ($order + 1) * 10,
                'send_to_erp' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
