<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Settings;

use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Work order §4 field_definitions rules, through the super admin editor.
 */
class FieldEditorTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function custom(array $overrides = []): array
    {
        return ['key' => 'farm_size', 'type' => 'text', 'label_en' => 'Farm size', 'label_ar' => 'مساحة المزرعة',
            'sort_order' => '60', 'is_active' => '1', 'send_to_erp' => '1', ...$overrides];
    }

    public function test_a_super_admin_adds_text_and_select_fields(): void
    {
        $super = $this->super();
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom())->assertRedirect(route('fields.index'));
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom([
            'key' => 'crop', 'type' => 'select', 'label_en' => 'Crop', 'label_ar' => 'المحصول',
            'new_options' => [['value' => 'wheat', 'label_en' => 'Wheat', 'label_ar' => 'قمح'], ['value' => '', 'label_en' => '', 'label_ar' => '']],
        ]))->assertRedirect();

        $crop = FieldDefinition::query()->where('key', 'crop')->sole();
        $this->assertFalse($crop->is_system);
        $this->assertSame([['value' => 'wheat', 'label_en' => 'Wheat', 'label_ar' => 'قمح', 'is_active' => true]], $crop->options);
        $this->assertDatabaseHas('audit_events', ['action' => 'field_created', 'target_id' => $crop->id]);
    }

    public function test_keys_follow_the_pattern_are_unique_and_avoid_system_and_reserved_names(): void
    {
        $super = $this->super();
        foreach (['Farm', '1farm', 'f', 'farm-size', str_repeat('a', 41), 'name', 'phone', 'submission_token'] as $key) {
            $this->actingAs($super)->post('/staff/settings/fields', $this->custom(['key' => $key]))->assertSessionHasErrors('key');
        }
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom())->assertSessionHasNoErrors();
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom())->assertSessionHasErrors('key');
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom(['key' => 'region_x', 'type' => 'region']))->assertSessionHasErrors('type');
    }

    public function test_at_most_15_custom_fields_are_active(): void
    {
        $super = $this->super();
        for ($i = 1; $i <= 15; $i++) {
            $this->actingAs($super)->post('/staff/settings/fields', $this->custom(['key' => "custom_{$i}"]))->assertSessionHasNoErrors();
        }
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom(['key' => 'custom_16']))->assertSessionHasErrors('is_active');
        $this->actingAs($super)->post('/staff/settings/fields', $this->custom(['key' => 'custom_16', 'is_active' => null]))->assertSessionHasNoErrors();

        $inactive = FieldDefinition::query()->where('key', 'custom_16')->sole();
        $this->actingAs($super)->put("/staff/settings/fields/{$inactive->id}", $this->custom(['is_active' => '1']))->assertSessionHasErrors('is_active');
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_key_and_type_never_change_and_name_and_phone_stay_required_and_active(): void
    {
        $super = $this->super();
        $phone = FieldDefinition::query()->where('key', 'phone')->sole();

        $this->actingAs($super)->put("/staff/settings/fields/{$phone->id}", [
            'key' => 'mobile', 'type' => 'text', 'label_en' => 'Mobile', 'label_ar' => 'الجوال', 'sort_order' => '15',
        ])->assertRedirect();

        $phone->refresh();
        $this->assertSame('phone', $phone->key);
        $this->assertSame('phone', $phone->type);
        $this->assertSame('Mobile', $phone->label_en);
        $this->assertTrue($phone->is_active);
        $this->assertTrue($phone->is_required);
    }

    public function test_option_values_are_kept_options_can_be_relabelled_deactivated_and_added(): void
    {
        $super = $this->super();
        $occupation = FieldDefinition::query()->where('key', 'occupation')->sole();
        $occupation->forceFill(['options' => [['value' => 'farmer', 'label_en' => 'Farmer', 'label_ar' => 'مزارع', 'is_active' => true]]])->save();

        $base = ['label_en' => 'Occupation', 'label_ar' => 'المهنة', 'sort_order' => '40', 'is_active' => '1', 'send_to_erp' => '1'];
        $this->actingAs($super)->put("/staff/settings/fields/{$occupation->id}", [...$base,
            'options' => [['value' => 'hacked', 'label_en' => 'Grower', 'label_ar' => 'مزارع']],
            'new_options' => [['value' => 'engineer', 'label_en' => 'Agricultural engineer', 'label_ar' => 'مهندس زراعي']],
        ])->assertRedirect();

        $this->assertSame([
            ['value' => 'farmer', 'label_en' => 'Grower', 'label_ar' => 'مزارع', 'is_active' => false],
            ['value' => 'engineer', 'label_en' => 'Agricultural engineer', 'label_ar' => 'مهندس زراعي', 'is_active' => true],
        ], $occupation->fresh()->options);

        $this->actingAs($super)->put("/staff/settings/fields/{$occupation->id}", [...$base,
            'new_options' => [['value' => 'engineer', 'label_en' => 'Again', 'label_ar' => 'مرة']],
        ])->assertSessionHasErrors('new_options');
    }
}
