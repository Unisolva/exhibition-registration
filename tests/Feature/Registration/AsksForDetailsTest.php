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
 * M10 CO-5: a list choice can ask for details ("Other"); choosing it needs a
 * "Please specify" text of at most 100 characters, stored as {key}_details.
 */
class AsksForDetailsTest extends TestCase
{
    use PublicForm;
    use RefreshDatabase;

    private const VISITOR = ['name' => 'Invented Visitor', 'phone' => '01001234567'];

    private function occupationWithOther(string $widget = 'select'): FieldDefinition
    {
        $occupation = FieldDefinition::query()->where('key', 'occupation')->sole();
        $occupation->forceFill([
            'options' => [
                ['value' => 'farm_owners', 'label_en' => 'Farm Owners', 'label_ar' => 'أصحاب المزارع', 'is_active' => true],
                ['value' => 'other', 'label_en' => 'Other', 'label_ar' => 'أخرى', 'is_active' => true, 'asks_details' => true],
            ],
            'widget' => $widget,
        ])->save();

        return $occupation;
    }

    public function test_the_field_form_marks_one_choice_and_refuses_two(): void
    {
        $super = User::factory()->superAdmin()->create();
        $field = FieldDefinition::query()->create([
            'key' => 'interest', 'is_system' => false, 'type' => 'select', 'label_en' => 'Interest', 'label_ar' => 'الاهتمام',
            'options' => [['value' => 'seeds', 'label_en' => 'Seeds', 'label_ar' => 'بذور', 'is_active' => true]],
            'is_required' => false, 'is_active' => true, 'sort_order' => 70, 'send_to_erp' => true,
        ]);
        $base = ['label_en' => 'Interest', 'label_ar' => 'الاهتمام', 'sort_order' => '70', 'is_active' => '1', 'audience' => 'all', 'widget' => 'select',
            'options' => [['label_en' => 'Seeds', 'label_ar' => 'بذور', 'is_active' => '1']]];

        $this->actingAs($super)->get("/staff/settings/fields/{$field->id}/edit")->assertSee('Asks for details');
        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$base,
            'new_options' => [['value' => 'other', 'label_en' => 'Other', 'label_ar' => 'أخرى', 'asks_details' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame('other', $field->fresh()->detailsOption());

        $this->actingAs($super)->put("/staff/settings/fields/{$field->id}", [...$base,
            'options' => [['label_en' => 'Seeds', 'label_ar' => 'بذور', 'is_active' => '1', 'asks_details' => '1'],
                ['label_en' => 'Other', 'label_ar' => 'أخرى', 'is_active' => '1', 'asks_details' => '1']]])
            ->assertSessionHasErrors(['new_options' => 'Only one choice can ask for details.']);
        $this->assertSame('other', $field->fresh()->detailsOption());
    }

    public function test_public_form_details_are_required_only_for_the_choice_and_ignored_otherwise(): void
    {
        $this->occupationWithOther();
        $exhibition = Exhibition::factory()->english()->create();

        // The box is in the page (shown without JavaScript) and tied to the choice.
        $this->get('/r/'.$exhibition->public_token)->assertOk()
            ->assertSee('data-details-for="occupation" data-details-value="other"', false)
            ->assertSee('<label for="f-occupation_details">Please specify</label>', false);

        // "Other" without details: refused, the error is on the box and in the summary.
        $html = $this->submitPublicForm($exhibition, [...self::VISITOR, 'occupation' => 'other'])->assertStatus(422)->getContent();
        $this->assertStringContainsString('<div class="error" id="f-occupation_details-error">Please specify your choice.</div>', $html);
        $this->assertStringContainsString('<a href="#f-occupation_details">Please specify: Please specify your choice.</a>', $html);

        // Over 100 characters: refused.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'occupation' => 'other', 'occupation_details' => str_repeat('x', 101)])->assertStatus(422);

        // Another choice: details ignored and not stored.
        $this->submitPublicForm($exhibition, [...self::VISITOR, 'occupation' => 'farm_owners', 'occupation_details' => 'ignored'])->assertRedirect();
        $first = Visitor::query()->sole();
        $this->assertSame('farm_owners', $first->occupation);
        $this->assertArrayNotHasKey('occupation_details', $first->extra ?? []);

        // "Other" with details: stored next to the choice.
        $this->submitPublicForm(Exhibition::factory()->english()->create(), [...self::VISITOR, 'occupation' => 'other', 'occupation_details' => '  Seed   supplier '])->assertRedirect();
        $second = Visitor::query()->latest('id')->first();
        $this->assertSame('other', $second->occupation);
        $this->assertSame('Seed supplier', $second->extra['occupation_details']);
    }

    public function test_staff_entry_with_radio_buttons_and_the_visitor_page_csv_and_erp_show_the_details(): void
    {
        $this->occupationWithOther('radio');
        $exhibition = Exhibition::factory()->create();
        $admin = User::factory()->admin()->create();
        $entry = fn (array $values) => [...self::VISITOR, ...$values, 'exhibition_id' => (string) $exhibition->id, 'submission_token' => (string) Str::ulid()];

        $this->actingAs($admin)->get("/staff/entry?exhibition={$exhibition->id}")->assertSee('data-details-for="occupation"', false);
        $this->actingAs($admin)->postJson('/staff/registrations', $entry(['occupation' => 'other']))
            ->assertStatus(422)->assertJsonPath('errors.occupation_details', 'Please specify your choice.');
        $token = (string) Str::ulid();
        $values = [...$entry(['occupation' => 'other', 'occupation_details' => 'Seed supplier']), 'submission_token' => $token];
        $this->actingAs($admin)->postJson('/staff/registrations', $values)->assertCreated();
        // A replay of the same submission is still recognised.
        $this->actingAs($admin)->postJson('/staff/registrations', $values)->assertOk();
        $visitor = Visitor::query()->sole();

        $this->actingAs($admin)->get("/staff/visitors/{$visitor->id}")->assertSee('Other (Seed supplier)');
        $this->assertStringContainsString('Other (Seed supplier)', $this->actingAs($admin)->get('/staff/visitors/export')->streamedContent());
        $payload = app(PayloadBuilder::class)->build($visitor, 'EXR-01TESTREFERENCE000000000');
        $this->assertSame('Other (Seed supplier)', $payload['function']);

        // Editing to another choice drops the details.
        $this->actingAs($admin)->put("/staff/visitors/{$visitor->id}", ['name' => $visitor->name, 'phone' => $visitor->phone_raw, 'occupation' => 'farm_owners', 'occupation_details' => 'x'])
            ->assertRedirect();
        $this->assertArrayNotHasKey('occupation_details', $visitor->fresh()->extra ?? []);
    }
}
