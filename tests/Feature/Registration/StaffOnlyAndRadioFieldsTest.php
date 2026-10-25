<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Registration;

use App\Erp\PayloadBuilder;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PublicForm;
use Tests\TestCase;

/**
 * M9 CO-1 to CO-3: staff-only fields and radio buttons.
 */
class StaffOnlyAndRadioFieldsTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Invented Visitor', 'phone' => '01001234567'];

    /** "Visitor type": staff only, radio, required, sent to the ERP. */
    private function visitorType(array $overrides = []): FieldDefinition
    {
        return FieldDefinition::query()->create([
            'key' => 'visitor_type', 'is_system' => false, 'type' => 'select', 'label_en' => 'Visitor type', 'label_ar' => '',
            'options' => [['value' => 'vip', 'label_en' => 'VIP', 'label_ar' => '', 'is_active' => true],
                ['value' => 'regular', 'label_en' => 'Regular', 'label_ar' => '', 'is_active' => true]],
            'is_required' => true, 'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true,
            'audience' => 'staff', 'widget' => 'radio', ...$overrides,
        ]);
    }

    private function entry(Exhibition $exhibition, array $values): array
    {
        return [...$values, 'exhibition_id' => (string) $exhibition->id, 'submission_token' => (string) Str::ulid()];
    }

    public function test_co1_the_field_form_saves_shown_on_and_show_as_and_arabic_is_optional_for_staff_only(): void
    {
        $super = User::factory()->superAdmin()->create();
        $base = ['key' => 'visitor_type', 'type' => 'select', 'label_en' => 'Visitor type', 'label_ar' => '', 'sort_order' => '60',
            'is_active' => '1', 'send_to_erp' => '1', 'new_options' => [['value' => 'vip', 'label_en' => 'VIP', 'label_ar' => '']]];

        // A public field needs its Arabic label.
        $this->actingAs($super)->post('/staff/settings/fields', [...$base, 'audience' => 'all'])->assertSessionHasErrors('label_ar');
        $this->actingAs($super)->post('/staff/settings/fields', [...$base, 'audience' => 'staff', 'widget' => 'radio'])->assertSessionHasNoErrors();
        $field = FieldDefinition::query()->where('key', 'visitor_type')->sole();
        $this->assertSame(['staff', 'radio', ''], [$field->audience, $field->widget, $field->label_ar]);

        // Switching it to the public form now needs the Arabic label.
        $update = ['label_en' => 'Visitor type', 'label_ar' => '', 'sort_order' => '60', 'is_active' => '1', 'send_to_erp' => '1', 'widget' => 'radio'];
        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$update, 'audience' => 'all'])->assertSessionHasErrors('label_ar');
        $this->assertSame('staff', $field->fresh()->audience);

        // A text field is never a radio group; a system field is always public.
        $this->actingAs($super)->post('/staff/settings/fields', ['key' => 'farm', 'type' => 'text', 'label_en' => 'Farm', 'label_ar' => '',
            'sort_order' => '70', 'is_active' => '1', 'audience' => 'staff', 'widget' => 'radio'])->assertSessionHasNoErrors();
        $this->assertSame('select', FieldDefinition::query()->where('key', 'farm')->sole()->widget);
        $occupation = FieldDefinition::query()->where('key', 'occupation')->sole();
        $this->actingAs($super)->put("/staff/settings/fields/{$occupation->id}", ['label_en' => 'Occupation', 'label_ar' => 'المهنة', 'sort_order' => '40',
            'is_active' => '1', 'audience' => 'staff', 'widget' => 'radio'])->assertSessionHasNoErrors();
        $this->assertSame(['all', 'radio'], [$occupation->fresh()->audience, $occupation->fresh()->widget]);

        // The form offers "Shown on" for custom fields only, and "Show as" for lists.
        $this->actingAs($super)->get('/staff/settings/fields/create')->assertSee('<legend>Shown on</legend>', false)->assertSee('<legend>Show as</legend>', false);
        $this->actingAs($super)->get("/staff/settings/fields/{$occupation->id}/edit")->assertDontSee('<legend>Shown on</legend>', false)->assertSee('<legend>Show as</legend>', false);
        $governorate = FieldDefinition::query()->where('key', 'governorate')->sole();
        $this->actingAs($super)->get("/staff/settings/fields/{$governorate->id}/edit")->assertDontSee('<legend>Show as</legend>', false);

        // The list shows the badge and the widget.
        $this->actingAs($super)->get('/staff/settings/fields')->assertSee('<span class="badge bg-orange-lt badge-staff">Staff only</span>', false)->assertSee('<span class="badge bg-blue-lt">Radio</span>', false);
    }

    public function test_co1_staff_only_fields_count_toward_the_15_active_custom_fields(): void
    {
        $super = User::factory()->superAdmin()->create();
        for ($i = 1; $i <= 15; $i++) {
            $this->actingAs($super)->post('/staff/settings/fields', ['key' => "custom_{$i}", 'type' => 'text', 'label_en' => "C{$i}", 'label_ar' => '',
                'sort_order' => '60', 'is_active' => '1', 'audience' => $i % 2 ? 'staff' : 'all', 'label_ar' => 'س'])->assertSessionHasNoErrors();
        }
        $this->actingAs($super)->post('/staff/settings/fields', ['key' => 'custom_16', 'type' => 'text', 'label_en' => 'C16', 'label_ar' => '',
            'sort_order' => '60', 'is_active' => '1', 'audience' => 'staff'])->assertSessionHasErrors('is_active');
    }

    public function test_co2_the_public_form_never_shows_or_checks_a_staff_only_field(): void
    {
        $this->visitorType();
        $exhibition = Exhibition::factory()->create();

        $this->get('/r/'.$exhibition->public_token)->assertOk()->assertDontSee('visitor_type', false)->assertDontSee('Visitor type');

        // Required on staff entry, yet a public submission without it is saved; a value sent anyway is ignored.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'visitor_type' => 'vip'])->assertRedirect('/r/'.$exhibition->public_token.'/thanks');
        $visitor = Visitor::query()->sole();
        $this->assertArrayNotHasKey('visitor_type', $visitor->extra ?? []);
    }

    public function test_co2_save_and_new_shows_and_checks_it_and_view_export_and_payload_include_it(): void
    {
        $this->visitorType();
        $exhibition = Exhibition::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get("/staff/entry?exhibition={$exhibition->id}")->assertOk()
            ->assertSee('<legend>Visitor type<span class="req" aria-hidden="true"> *</span></legend>', false);
        $this->actingAs($admin)->postJson('/staff/registrations', $this->entry($exhibition, self::VISITOR))
            ->assertStatus(422)->assertJsonPath('errors.visitor_type', 'Choose one.');
        $this->actingAs($admin)->postJson('/staff/registrations', $this->entry($exhibition, [...self::VISITOR, 'visitor_type' => 'vip']))->assertCreated();

        $visitor = Visitor::query()->sole();
        $this->assertSame('vip', $visitor->extra['visitor_type']);
        $this->actingAs($admin)->get("/staff/visitors/{$visitor->id}")->assertSee('Visitor type')->assertSee('VIP');
        $this->assertStringContainsString('Visitor type', $this->actingAs($admin)->get('/staff/visitors/export')->streamedContent());
        $payload = app(PayloadBuilder::class)->build($visitor, 'EXR-01TESTREFERENCE000000000');
        $this->assertStringContainsString('Visitor type: VIP', $payload['description']);
    }

    public function test_co2_visitor_edit_shows_staff_only_fields_for_staff_entries_only(): void
    {
        $this->visitorType(['is_required' => false]);
        $admin = User::factory()->admin()->create();
        $public = Visitor::factory()->create(['source' => 'public']);
        $staffEntry = Visitor::factory()->enteredBy($admin)->create();

        $this->actingAs($admin)->get("/staff/visitors/{$public->id}/edit")->assertOk()->assertDontSee('Visitor type');
        $this->actingAs($admin)->put("/staff/visitors/{$public->id}", ['name' => $public->name, 'phone' => $public->phone_raw, 'visitor_type' => 'vip'])->assertRedirect();
        $this->assertArrayNotHasKey('visitor_type', $public->fresh()->extra ?? []);

        $this->actingAs($admin)->get("/staff/visitors/{$staffEntry->id}/edit")->assertOk()->assertSee('<legend>Visitor type</legend>', false);
        $this->actingAs($admin)->put("/staff/visitors/{$staffEntry->id}", ['name' => $staffEntry->name, 'phone' => $staffEntry->phone_raw, 'visitor_type' => 'regular'])->assertRedirect();
        $this->assertSame('regular', $staffEntry->fresh()->extra['visitor_type']);
    }

    public function test_co3_radio_groups_optional_with_not_set_required_with_nothing_preselected_and_on_the_public_form(): void
    {
        // A public optional radio list, in Arabic.
        FieldDefinition::query()->create([
            'key' => 'interest', 'is_system' => false, 'type' => 'select', 'label_en' => 'Interest', 'label_ar' => 'الاهتمام',
            'options' => [['value' => 'seeds', 'label_en' => 'Seeds', 'label_ar' => 'بذور', 'is_active' => true],
                ['value' => 'tools', 'label_en' => 'Tools', 'label_ar' => 'معدات', 'is_active' => true]],
            'is_required' => false, 'is_active' => true, 'sort_order' => 70, 'send_to_erp' => true, 'audience' => 'all', 'widget' => 'radio',
        ]);
        $exhibition = Exhibition::factory()->create();

        $html = $this->get('/r/'.$exhibition->public_token)->assertOk()->getContent();
        $this->assertStringContainsString('<fieldset class="field field-radio" id="f-interest"', $html);
        $this->assertStringContainsString('<legend>الاهتمام</legend>', $html);
        $this->assertMatchesRegularExpression('#value=""\s+id="f-interest-0"\s+checked#', $html);
        $this->assertStringContainsString('غير محدد', $html);

        // "Not set" submits an empty value; a choice is stored.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'interest' => ''])->assertRedirect();
        $this->assertNull(Visitor::query()->sole()->extra['interest']);

        // A required radio group: no "Not set", nothing checked, "Choose one" tied to the fieldset, focus on the first radio.
        FieldDefinition::query()->where('key', 'interest')->update(['is_required' => true]);
        $html = $this->submitPublicForm(Exhibition::factory()->english()->create(), self::VISITOR)->assertStatus(422)->getContent();
        $this->assertStringNotContainsString('Not set', $html);
        $this->assertStringNotContainsString(' checked', $html);
        $this->assertMatchesRegularExpression('#<fieldset class="field field-radio" id="f-interest"\s+aria-describedby="f-interest-error"#', $html);
        $this->assertStringContainsString('<div class="error" id="f-interest-error">Choose one.</div>', $html);
        $this->assertMatchesRegularExpression('#id="f-interest-0"[^>]*aria-invalid="true"[^>]*autofocus#s', $html);
    }
}
