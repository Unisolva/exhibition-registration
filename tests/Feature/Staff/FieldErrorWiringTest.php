<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Database\Factories\UserFactory;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * M8 CO-9: every staff form ties its error messages to the control. Invalid
 * data is posted to each form; the re-shown form must mark the control
 * aria-invalid and point aria-describedby at an element holding the message.
 */
class FieldErrorWiringTest extends TestCase
{
    use PushFixtures;
    use RefreshDatabase;

    /** The control with this id is invalid and described by an element containing the message. */
    private function assertWired(string $html, string $id, string $message): void
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $control = $xpath->query("//*[@id='{$id}']")->item(0);
        $this->assertNotNull($control, "no element #{$id}");
        $this->assertSame('true', $control->getAttribute('aria-invalid'), "#{$id} is not aria-invalid");

        $texts = [];
        foreach (preg_split('/\s+/', trim($control->getAttribute('aria-describedby')), -1, PREG_SPLIT_NO_EMPTY) as $ref) {
            $texts[] = trim((string) $xpath->query("//*[@id='{$ref}']")->item(0)?->textContent);
        }
        $this->assertNotSame('', $message);
        $this->assertTrue(
            collect($texts)->contains(fn ($text) => str_contains($text, $message)),
            "#{$id} is not described by \"{$message}\" (described by: ".json_encode($texts, JSON_UNESCAPED_UNICODE).')'
        );
    }

    /** The first flashed error for $key in $bag (the session is JSON-serialised, so a reloaded bag is an array). */
    private function flashedError(string $bag, string $key): string
    {
        $errors = app('session.store')->get('errors');
        $messages = is_array($errors) ? ($errors[$bag]['messages'] ?? []) : $errors->getBag($bag)->messages();
        $this->assertArrayHasKey($key, $messages, "no flashed error for {$key} in {$bag}: ".json_encode($errors));

        return (string) $messages[$key][0];
    }

    /** Posts from the form page, then reloads it and checks the wiring for the first error of $key. */
    private function postAndCheck(User $user, string $formUrl, string $method, string $action, array $data, string $id, string $key, string $bag = 'default'): void
    {
        $this->actingAs($user)->from($formUrl)->call($method, $action, $data)->assertRedirect();
        $message = $this->flashedError($bag, $key);
        $this->assertWired($this->actingAs($user)->get($formUrl)->assertOk()->getContent(), $id, $message);
    }

    public function test_sign_in(): void
    {
        $this->from('/staff/login')->post('/staff/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password'])->assertRedirect();
        $message = $this->flashedError('default', 'email');
        $this->assertWired($this->get('/staff/login')->getContent(), 'email', $message);
    }

    public function test_password_change(): void
    {
        $user = User::factory()->create();
        $this->postAndCheck($user, '/staff/password', 'PUT', '/staff/password',
            ['current_password' => 'not-it', 'password' => 'short', 'password_confirmation' => 'short'], 'current_password', 'current_password');
        $this->postAndCheck($user, '/staff/password', 'PUT', '/staff/password',
            ['current_password' => UserFactory::PASSWORD, 'password' => 'short', 'password_confirmation' => 'short'], 'password', 'password');
    }

    public function test_users(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->postAndCheck($super, '/staff/users/create', 'POST', '/staff/users', ['name' => '', 'email' => 'not-an-email', 'role' => 'staff'], 'name', 'name');
        $this->postAndCheck($super, '/staff/users/create', 'POST', '/staff/users', ['name' => 'X', 'email' => 'not-an-email', 'role' => 'staff'], 'email', 'email');

        $staff = User::factory()->create();
        $this->postAndCheck($super, "/staff/users/{$staff->id}/edit", 'PUT', "/staff/users/{$staff->id}",
            ['name' => 'Renamed', 'email' => $staff->email, 'role' => 'nonsense', 'is_active' => '1'], 'role', 'role');
    }

    public function test_exhibitions(): void
    {
        $admin = User::factory()->admin()->create();
        $this->postAndCheck($admin, '/staff/exhibitions/create', 'POST', '/staff/exhibitions',
            ['name' => 'Fair', 'starts_on' => '2026-11-05', 'ends_on' => '2026-11-01', 'default_locale' => 'ar'], 'ends_on', 'ends_on');

        $exhibition = Exhibition::factory()->create();
        $this->postAndCheck($admin, "/staff/exhibitions/{$exhibition->id}/edit", 'PUT', "/staff/exhibitions/{$exhibition->id}",
            ['name' => 'Fair', 'starts_on' => '2026-11-05', 'default_locale' => 'fr'], 'default_locale', 'default_locale');
    }

    public function test_settings_branding_and_fields(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->postAndCheck($super, '/staff/settings', 'PUT', '/staff/settings',
            ['default_country' => 'EG', 'display_timezone' => 'Africa/Cairo', 'retention_months' => '0'], 'retention_months', 'retention_months');
        $this->postAndCheck($super, '/staff/settings/branding', 'PUT', '/staff/settings/branding',
            ['org_name_en' => 'A', 'org_name_ar' => 'ب', 'brand_color' => '#FFFF00'], 'brand_color', 'brand_color');

        $this->postAndCheck($super, '/staff/settings/fields/create', 'POST', '/staff/settings/fields',
            ['key' => 'Bad Key', 'type' => 'text', 'label_en' => 'X', 'label_ar' => 'س', 'sort_order' => '60'], 'key', 'key');
        $this->postAndCheck($super, '/staff/settings/fields/create', 'POST', '/staff/settings/fields',
            ['key' => 'crop', 'type' => 'select', 'label_en' => 'Crop', 'label_ar' => 'س', 'sort_order' => '60',
                'new_options' => [['value' => 'Not Valid!', 'label_en' => 'W', 'label_ar' => 'ق']]], 'new-0-value', 'new_options.0.value');

        $field = FieldDefinition::query()->where('key', 'occupation')->sole();
        $this->postAndCheck($super, "/staff/settings/fields/{$field->id}/edit", 'PUT', "/staff/settings/fields/{$field->id}",
            ['label_en' => '', 'label_ar' => 'س', 'sort_order' => '40'], 'label_en', 'label_en');
    }

    public function test_erp_settings_and_each_connection_block(): void
    {
        $super = User::factory()->superAdmin()->create();
        app(Settings::class)->set('erp_mode', 'odoo');

        $this->postAndCheck($super, '/staff/erp', 'PUT', '/staff/erp/settings',
            ['erp_mode' => 'odoo', 'odoo_version' => '18.0', 'lead_name_template' => '', 'odoo_tag_ids' => ''], 'lead_name_template', 'lead_name_template');

        foreach (['test', 'live'] as $kind) {
            $this->postAndCheck($super, '/staff/erp', 'PUT', "/staff/erp/blocks/{$kind}",
                ['base_url' => 'http://not-https.example.test/path', 'database' => 'db', 'login' => 'crm@example.test'], "{$kind}-url", 'base_url', "block_{$kind}");
            $this->postAndCheck($super, '/staff/erp', 'PUT', "/staff/erp/blocks/{$kind}",
                ['base_url' => 'https://erp.example.test', 'database' => '', 'login' => 'crm@example.test'], "{$kind}-db", 'database', "block_{$kind}");
        }

        // An error in one block is not shown in the other.
        $this->actingAs($super)->from('/staff/erp')->put('/staff/erp/blocks/test', ['base_url' => 'https://erp.example.test', 'database' => '', 'login' => 'x']);
        $html = $this->actingAs($super)->get('/staff/erp')->getContent();
        $this->assertWired($html, 'test-db', 'The database field is required.');
        $this->assertStringNotContainsString('id="live-db-error"', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="live-db"[^>]*aria-invalid/', $html);
    }

    public function test_trash_confirmation(): void
    {
        $super = User::factory()->superAdmin()->create();
        $visitor = Visitor::factory()->trashed($super)->create();

        $this->postAndCheck($super, '/staff/trash', 'DELETE', "/staff/trash/{$visitor->id}", ['confirm' => 'nope'], "confirm-{$visitor->id}", 'confirm', 'delete'.$visitor->id);
    }

    public function test_visitor_edit_and_save_and_new_without_javascript(): void
    {
        $admin = User::factory()->admin()->create();
        $visitor = Visitor::factory()->create();

        $this->postAndCheck($admin, "/staff/visitors/{$visitor->id}/edit", 'PUT', "/staff/visitors/{$visitor->id}", ['name' => '', 'phone' => '01001234567'], 'f-name', 'name');

        $exhibition = Exhibition::factory()->create();
        $html = $this->actingAs($admin)->post('/staff/registrations', [
            'exhibition_id' => (string) $exhibition->id, 'submission_token' => (string) Str::ulid(), 'name' => 'Someone', 'phone' => '12',
        ])->assertStatus(422)->getContent();
        $this->assertWired($html, 'f-phone', __('public.error.phone_short', ['field' => 'Phone'], 'en'));
    }
}
