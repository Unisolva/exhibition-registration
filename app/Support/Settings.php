<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Installation settings (work order §4 "settings"). Values are JSON in the
 * settings table; a missing row reads as the product default below. Rows are
 * loaded once per request.
 */
class Settings
{
    public const DEFAULTS = [
        'org_name_en' => '',
        'org_name_ar' => '',
        'logo' => null,
        'logo_hash' => null,
        'logo_width' => null,
        'logo_height' => null,
        'brand_color' => '#1F4E79',
        // M11 v1.1 CO-1: colour of the gift instruction line on the thank-you page; null keeps the normal text colour.
        'gift_text_color' => null,
        'show_powered_by' => true,
        'display_timezone' => 'Africa/Cairo',
        'public_registration_enabled' => true,
        'erp_push_enabled' => false,
        'default_country' => null,
        'privacy_url' => null,
        'retention_months' => 24,
        'erp_mode' => 'none',
        'odoo_version' => '18',
        'lead_name_template' => '{exhibition}: {visitor_name}',
        'odoo_campaign_id' => null,
        'odoo_source_id' => null,
        'odoo_tag_ids' => [],
        'create_contact' => false,
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key): mixed
    {
        $this->loaded ??= $this->load();

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        if ($key === 'default_country') {
            return config('exhibition.default_country');
        }

        return self::DEFAULTS[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $userId = null): void
    {
        DB::table('settings')->upsert(
            [[
                'key' => $key,
                'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_by' => $userId,
                'updated_at' => now(),
            ]],
            ['key'],
            ['value', 'updated_by', 'updated_at'],
        );

        $this->loaded = null;
    }

    public function has(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->exists();
    }

    public function forget(): void
    {
        $this->loaded = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        return DB::table('settings')->pluck('value', 'key')
            ->map(fn (?string $json) => $json === null ? null : json_decode($json, true))
            ->all();
    }
}
