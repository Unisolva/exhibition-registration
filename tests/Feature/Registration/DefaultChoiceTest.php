<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M10.1 CO-1: a list choice can be "Selected by default"; new entries start
 * with it, a submitted or stored choice wins.
 */
class DefaultChoiceTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    /** "Visitor type": staff only, radio, required, "No" selected by default. */
    private function visitorType(array $overrides = []): FieldDefinition
    {
        return FieldDefinition::query()->create([
            'key' => 'visitor_type', 'is_system' => false, 'type' => 'select', 'label_en' => 'Visitor type', 'label_ar' => '',
            'options' => [['value' => 'yes', 'label_en' => 'Yes', 'label_ar' => '', 'is_active' => true],
                ['value' => 'no', 'label_en' => 'No', 'label_ar' => '', 'is_active' => true, 'default' => true]],
            'is_required' => true, 'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true,
            'audience' => 'staff', 'widget' => 'radio', ...$overrides,
        ]);
    }

    public function test_the_field_form_stores_one_default_refuses_two_and_never_a_deactivated_choice(): void
    {
        $super = User::factory()->superAdmin()->create();
        $field = $this->visitorType(['options' => [['value' => 'yes', 'label_en' => 'Yes', 'label_ar' => '', 'is_active' => true],
            ['value' => 'no', 'label_en' => 'No', 'label_ar' => '', 'is_active' => true]]]);
        $base = ['label_en' => 'Visitor type', 'label_ar' => '', 'sort_order' => '60', 'is_active' => '1', 'is_required' => '1',
            'send_to_erp' => '1', 'audience' => 'staff', 'widget' => 'radio'];

        $this->actingAs($super)->get("/staff/settings/fields/{$field->id}/edit")->assertSee('Selected by default');
        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$base, 'options' => [
            ['label_en' => 'Yes', 'is_active' => '1'], ['label_en' => 'No', 'is_active' => '1', 'default' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame('no', $field->fresh()->defaultOption());
        $this->assertTrue($field->fresh()->options[1]['default']);

        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$base, 'options' => [
            ['label_en' => 'Yes', 'is_active' => '1', 'default' => '1'], ['label_en' => 'No', 'is_active' => '1', 'default' => '1']]])
            ->assertSessionHasErrors(['new_options' => 'Only one choice can be selected by default.']);
        $this->assertSame('no', $field->fresh()->defaultOption());

        // A deactivated choice is never the default.
        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$base, 'options' => [
            ['label_en' => 'Yes', 'is_active' => '1'], ['label_en' => 'No', 'default' => '1']]])->assertSessionHasNoErrors();
        $this->assertNull($field->fresh()->defaultOption());
        $this->assertFalse($field->fresh()->options[1]['default']);
    }

    public function test_save_and_new_starts_with_the_default_and_marks_it_for_the_reset(): void
    {
        $this->visitorType();
        $exhibition = Exhibition::factory()->create();
        $staff = User::factory()->create();

        $html = $this->actingAs($staff)->get("/staff/entry?exhibition={$exhibition->id}")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="visitor_type" value="no" id="f-visitor_type-1"\s+checked data-default#', $html);
        $this->assertMatchesRegularExpression('#name="visitor_type" value="yes" id="f-visitor_type-0"\s+required#', $html);
    }

    public function test_public_form_default_on_a_dropdown_and_an_optional_radio_list_and_the_visitors_choice_wins_after_an_error(): void
    {
        $exhibition = Exhibition::factory()->english()->create();
        $crop = FieldDefinition::query()->create([
            'key' => 'crop', 'is_system' => false, 'type' => 'select', 'label_en' => 'Crop', 'label_ar' => 'المحصول',
            'options' => [['value' => 'wheat', 'label_en' => 'Wheat', 'label_ar' => 'قمح', 'is_active' => true],
                ['value' => 'rice', 'label_en' => 'Rice', 'label_ar' => 'أرز', 'is_active' => true, 'default' => true]],
            'is_required' => false, 'is_active' => true, 'sort_order' => 70, 'send_to_erp' => true,
        ]);

        $html = $this->get('/r/'.$exhibition->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('<option value="rice" selected data-default>Rice</option>', $html);

        // After an error the visitor's own choice (here the empty one) is kept.
        $html = $this->submitPublicForm($exhibition, ['name' => '', 'phone' => '01001234567', 'crop' => 'wheat'])->assertStatus(422)->getContent();
        $this->assertStringContainsString('<option value="wheat" selected>Wheat</option>', $html);
        $this->assertMatchesRegularExpression('#<option value="rice"\s+data-default>Rice</option>#', $html);

        // An optional radio list keeps "Not set" but starts on the default.
        $crop->forceFill(['widget' => 'radio'])->save();
        $html = $this->get('/r/'.$exhibition->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('Not set', $html);
        $this->assertMatchesRegularExpression('#value="rice" id="f-crop-2"\s+checked data-default#', $html);
        $this->assertDoesNotMatchRegularExpression('#value="" id="f-crop-0"\s+checked#', $html);
        $html = $this->submitPublicForm($exhibition, ['name' => '', 'phone' => '01001234567', 'crop' => ''])->assertStatus(422)->getContent();
        $this->assertMatchesRegularExpression('#value="" id="f-crop-0"\s+checked#', $html);
    }

    public function test_the_edit_form_shows_the_stored_value_and_the_default_only_when_nothing_is_stored(): void
    {
        $this->visitorType();
        $admin = User::factory()->admin()->create();
        $vip = Visitor::factory()->enteredBy($admin)->create(['extra' => ['visitor_type' => 'yes']]);
        $empty = Visitor::factory()->enteredBy($admin)->create(['extra' => []]);

        $this->assertMatchesRegularExpression('#value="yes" id="f-visitor_type-0"\s+checked#',
            $this->actingAs($admin)->get("/staff/visitors/{$vip->id}/edit")->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#value="no" id="f-visitor_type-1"\s+checked data-default#',
            $this->actingAs($admin)->get("/staff/visitors/{$empty->id}/edit")->assertOk()->getContent());
        // Existing visitors are not changed by the setting.
        $this->assertSame([], $empty->fresh()->extra ?? []);
    }
}
