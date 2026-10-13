<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\PayloadBuilder;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work order §8 "Payload (Odoo 18)".
 */
class PayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_payload_follows_the_mapping(): void
    {
        $settings = app(Settings::class);
        $settings->set('lead_name_template', 'Fair lead – {exhibition} – {visitor_name}');
        $settings->set('odoo_campaign_id', 12);
        $settings->set('odoo_source_id', 3);
        $settings->set('odoo_tag_ids', [4, 9]);
        FieldDefinition::query()->where('key', 'occupation')->update(['options' => json_encode([
            ['value' => 'farmer', 'label_en' => 'Farmer', 'label_ar' => 'مزارع', 'is_active' => true],
        ])]);
        FieldDefinition::query()->create([
            'key' => 'farm_size', 'is_system' => false, 'type' => 'text', 'label_en' => 'Farm size', 'label_ar' => 'المساحة',
            'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true,
        ]);
        FieldDefinition::query()->create([
            'key' => 'internal_note', 'is_system' => false, 'type' => 'text', 'label_en' => 'Internal note', 'label_ar' => 'ملاحظة',
            'is_active' => true, 'sort_order' => 70, 'send_to_erp' => false,
        ]);
        $exhibition = Exhibition::factory()->create(['name' => 'Delta <Fair>', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03']);
        $visitor = Visitor::factory()->at($exhibition)->create([
            'name' => 'Visitor & Son', 'email' => 'v@example.test', 'occupation' => 'farmer', 'region_code' => 'ALX',
            'region_label' => 'Alexandria', 'extra' => ['farm_size' => '12 feddan', 'internal_note' => 'do not send'],
            'created_at' => '2026-11-01 08:30:00',
        ]);

        $payload = app(PayloadBuilder::class)->build($visitor, 'EXR-01JTESTREFERENCE000000000A');

        $this->assertSame('Fair lead – Delta <Fair> – Visitor & Son', $payload['name']);
        $this->assertSame('lead', $payload['type']);
        $this->assertFalse($payload['user_id']);
        $this->assertSame('Visitor & Son', $payload['contact_name']);
        $this->assertSame($visitor->phone_e164, $payload['phone']);
        $this->assertSame('v@example.test', $payload['email_from']);
        $this->assertSame('Farmer', $payload['function']);
        $this->assertSame('EG', $payload['_country_code']);
        $this->assertSame('ALX', $payload['_region_erp_code']);
        $this->assertSame(12, $payload['campaign_id']);
        $this->assertSame(3, $payload['source_id']);
        $this->assertSame([4, 9], $payload['tag_ids']);

        $description = $payload['description'];
        $this->assertStringStartsWith('<p>Ref: EXR-01JTESTREFERENCE000000000A<br>', $description);
        $this->assertStringContainsString('Exhibition: Delta &lt;Fair&gt; (2026-11-01 to 2026-11-03)', $description);
        $this->assertStringContainsString('Source: QR form', $description);
        $this->assertStringContainsString('Registered: 2026-11-01 10:30 (Africa/Cairo)', $description);
        $this->assertStringContainsString('Name: Visitor &amp; Son', $description);
        $this->assertStringContainsString('Farm size: 12 feddan', $description);
        $this->assertStringNotContainsString('do not send', $description);
        $this->assertStringNotContainsString('<Fair>', $description);
    }

    public function test_optional_parts_are_omitted_and_an_invalid_phone_is_sent_as_typed(): void
    {
        $visitor = Visitor::factory()->create([
            'email' => null, 'occupation' => null, 'region_code' => null, 'region_label' => null,
            'phone_raw' => '+999 1234567', 'phone_e164' => null, 'phone_valid' => false,
        ]);

        $payload = app(PayloadBuilder::class)->build($visitor, 'EXR-01JTESTREFERENCE000000000B');

        $this->assertSame('+999 1234567', $payload['phone']);
        foreach (['email_from', 'function', '_country_code', '_region_erp_code', 'campaign_id', 'source_id', 'tag_ids'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }
}
