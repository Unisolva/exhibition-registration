<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\ErpConnection;
use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Support\PublicForm;
use Tests\Support\PushFixtures;
use Tests\TestCase;

/**
 * M8 PR 4 (CO-14 to CO-22): flow and copy.
 */
class FlowAndCopyTest extends TestCase
{
    use PublicForm;
    use PushFixtures;
    use RefreshDatabase;

    /** @return list<string> every Blade view */
    private function views(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        $this->assertGreaterThan(40, count($files));

        return $files;
    }

    public function test_co14_the_exhibition_name_is_the_heading_of_the_public_form_and_the_logo_has_empty_alt(): void
    {
        $settings = app(Settings::class);
        $settings->set('org_name_en', 'Sample Agri Company');
        $settings->set('logo', ['mime' => 'image/png', 'data' => 'AA==']);
        $settings->set('logo_hash', 'abcdef0123456789');
        $settings->set('logo_width', 300);
        $settings->set('logo_height', 100);
        $exhibition = Exhibition::factory()->english()->create(['name' => 'Delta Farm Days']);

        $this->get('/r/'.$exhibition->public_token)->assertOk()
            ->assertSee('<h1 class="exhibition-title">Delta Farm Days</h1>', false)
            ->assertSee('Register your visit: leave your details and our team will contact you.')
            ->assertSee('alt="" width="300" height="100"', false)
            ->assertDontSee('<h1>Register your visit</h1>', false);
    }

    public function test_co15_the_poster_calls_to_scan_in_both_languages_with_dates_and_a_print_button(): void
    {
        $admin = User::factory()->admin()->create();
        $arabic = Exhibition::factory()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-05']);

        $this->actingAs($admin)->get("/staff/exhibitions/{$arabic->id}/poster")->assertOk()
            ->assertSeeInOrder([
                '<p class="poster-dates" lang="ar" dir="rtl">',
                '<p class="poster-cta" lang="ar" dir="rtl">'.__('public.poster_cta', [], 'ar').'</p>',
                '<p class="poster-cta-second" lang="en" dir="ltr">Scan to register</p>',
                'class="poster-qr"',
            ], false)
            ->assertSee('<button type="button" class="btn" data-print>Print</button>', false);

        $english = Exhibition::factory()->english()->create(['starts_on' => '2026-11-03', 'ends_on' => '2026-11-05']);
        $this->actingAs($admin)->get("/staff/exhibitions/{$english->id}/poster")->assertOk()
            ->assertSeeInOrder(['<p class="poster-dates" lang="en" dir="ltr">3 November 2026 – 5 November 2026</p>',
                '<p class="poster-cta" lang="en" dir="ltr">Scan to register</p>', '<p class="poster-cta-second" lang="ar" dir="rtl">'], false);
    }

    public function test_co16_staff_go_to_save_and_new_and_managers_see_the_overview(): void
    {
        $this->actingAs(User::factory()->staff()->create())->get('/staff')->assertRedirect(route('staff.entry'));

        $settings = app(Settings::class);
        $settings->set('display_timezone', 'Africa/Cairo');
        $this->travelTo('2026-11-03 21:30:00'); // 23:30 in Cairo
        $open = Exhibition::factory()->create(['name' => 'Open Fair']);
        Exhibition::factory()->closed()->create(['name' => 'Closed Fair']);
        Visitor::factory()->at($open)->create(['created_at' => '2026-11-03 21:00:00']);   // today in Cairo
        Visitor::factory()->at($open)->create(['created_at' => '2026-11-02 21:59:00']);   // 23:59 yesterday in Cairo
        Visitor::factory()->at($open)->create(['created_at' => '2026-11-02 22:01:00']);   // 00:01 today in Cairo
        Visitor::factory()->at($open)->trashed()->create(['created_at' => '2026-11-03 20:00:00']);

        $admin = User::factory()->admin()->create();
        $html = $this->actingAs($admin)->get('/staff')->assertOk()
            ->assertSee('Open Fair')->assertDontSee('Closed Fair')
            ->assertSee(route('visitors.index', ['exhibition' => $open->id]), false)
            ->assertDontSee('Sending to Odoo')
            ->getContent();
        // M10 CO-3: a card per open exhibition with the QR / staff split.
        $this->assertMatchesRegularExpression('#Today</div>\s*<div class="stat-value">2</div>\s*<div class="stat-split">QR 2 · staff 0</div>#', $html);
        $this->assertMatchesRegularExpression('#In total</div>\s*<div class="stat-value">3</div>\s*<div class="stat-split">QR 3 · staff 0</div>#', $html);

        // Odoo mode: items needing review, sending on or off, the active connection; the ERP link for super admins only.
        $this->readyToPush('test');
        $this->queue([Visitor::factory()->at($open)->create()]);
        $html = $this->actingAs($admin)->get('/staff')->assertOk()
            ->assertSee('Sending to Odoo is <strong>on</strong>', false)
            ->assertSee('Active connection: <strong>TEST</strong>', false)
            ->assertSee('Nothing needs checking in Odoo.')
            ->assertDontSee('>ERP settings</a>', false)->getContent();
        $this->assertNotSame('', $html);
        $this->actingAs(User::factory()->superAdmin()->create())->get('/staff')->assertSee('>ERP settings</a>', false);
    }

    public function test_co17_no_erp_column_or_filter_in_mode_none_and_plain_labels_in_odoo_mode(): void
    {
        $admin = User::factory()->admin()->create();
        Visitor::factory()->create();

        $this->actingAs($admin)->get('/staff/visitors')->assertOk()
            ->assertDontSee('<th>ERP</th>', false)->assertDontSee('id="sync"', false)->assertDontSee('data-label="ERP"', false);

        $test = $this->readyToPush('test');
        $sent = Visitor::factory()->create(['name' => 'Already Sent']);
        DB::table('lead_syncs')->insert(['visitor_id' => $sent->id, 'connection_id' => $test->id, 'ext_ref' => 'EXR-01TESTREFERENCE000000000', 'state' => 'succeeded',
            'erp_lead_id' => 5, 'succeeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get('/staff/visitors')->assertOk()
            ->assertSee('<th>ERP</th>', false)
            ->assertSee('<td data-label="ERP">Sent to TEST</td>', false)
            ->assertSee('<td data-label="ERP">Not sent</td>', false)
            ->assertSeeInOrder(['<option value="never" >Not sent</option>', '<option value="queued" >Waiting</option>',
                '<option value="succeeded" >Sent to TEST</option>', '<option value="failed" >Failed</option>', '<option value="needs_review" >Check in Odoo</option>'], false);
    }

    public function test_co18_switching_goes_through_a_confirmation_page_and_the_active_block_changes_nothing(): void
    {
        $super = User::factory()->superAdmin()->create();
        $live = $this->readyToPush('live');
        $test = $this->testedConnection('test');

        $this->actingAs($super)->get('/staff/erp')->assertOk()
            ->assertSeeInOrder(['<legend>Active connection</legend>', 'name="kind" value="test"', 'name="kind" value="live"', 'Use the selected connection…', 'class="erp-blocks"'], false)
            ->assertSee('<button type="submit" class="btn btn-outline-primary">Stop sending</button>', false);

        // The confirmation names the chosen block, its URL and database, and posts to erp.activate.
        $this->actingAs($super)->get('/staff/erp/activate?kind=test')->assertOk()
            ->assertSee('<div class="destination-kind">TEST</div>', false)
            ->assertSee($test->base_url.' / '.$test->database)
            ->assertSee('action="'.route('erp.activate').'"', false)
            ->assertSee('Make TEST active');
        $this->assertTrue($live->fresh()->is_active);

        // Choosing the block that is already active changes nothing.
        $this->actingAs($super)->get('/staff/erp/activate?kind=live')->assertOk()->assertSee('LIVE is already active. Nothing changes.')->assertDontSee('Make LIVE active');
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'live'])->assertSessionHas('status', 'LIVE is already active. Nothing changed.');
        $this->assertDatabaseMissing('audit_events', ['action' => 'connection_activated']);

        // Confirming switches (the v2.9 rules still apply: nothing is unfinished here).
        $this->actingAs($super)->post('/staff/erp/activate', ['kind' => 'test'])->assertSessionHasNoErrors();
        $this->assertTrue($test->fresh()->is_active);
        $this->assertFalse($live->fresh()->is_active);

        // An unsaved block cannot be chosen.
        ErpConnection::query()->where('kind', 'live')->update(['archived_at' => now()]);
        $this->actingAs($super)->get('/staff/erp/activate?kind=live')->assertRedirect(route('erp.show'))->assertSessionHasErrors('kind');
    }

    public function test_co19_filters_fold_count_matches_and_empty_states(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get('/staff/visitors')->assertOk()
            ->assertSee('No visitors yet. Entries from the QR form and Save &amp; New appear here.', false)
            ->assertSee('<details class="filter-details" >', false);

        $exhibition = Exhibition::factory()->create();
        Visitor::factory()->count(3)->at($exhibition)->create();
        $this->actingAs($admin)->get('/staff/visitors')->assertSee('<p class="result-count" role="status">3 visitors</p>', false);

        $this->actingAs($admin)->get('/staff/visitors?q=nobody-at-all&exhibition='.$exhibition->id)->assertOk()
            ->assertSee('<details class="filter-details"  open >', false)
            ->assertSee('Filters <span class="hint">(2 active)</span>', false)
            ->assertSee('No visitors match these filters.')
            ->assertSee('>Clear filters</a>', false);
    }

    public function test_co20_close_and_reset_go_through_confirmation_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create(['name' => 'Sahara Sample Fair']);

        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}")
            ->assertSee(route('exhibitions.close-confirm', $exhibition), false)->assertDontSee('action="'.route('exhibitions.close', $exhibition).'"', false);
        $this->actingAs($admin)->get("/staff/exhibitions/{$exhibition->id}/close")->assertOk()
            ->assertSee('Close registration for Sahara Sample Fair?')
            ->assertSee('Visitors who scan the QR will see "Registration is closed", and staff can no longer enter visitors for it in Save &amp; New.', false)
            ->assertSee('action="'.route('exhibitions.close', $exhibition).'"', false);
        $this->assertTrue($exhibition->fresh()->is_open);

        $staff = User::factory()->staff()->create(['name' => 'Nour Sample']);
        $this->actingAs($admin)->get("/staff/users/{$staff->id}/edit")->assertSee(route('users.reset-password-confirm', $staff), false);
        $this->actingAs($admin)->get("/staff/users/{$staff->id}/reset-password")->assertOk()
            ->assertSee("Reset Nour Sample's password?", false)
            ->assertSee('They are signed out on every device and need the new one-time password.');
        $this->assertFalse($staff->fresh()->must_change_password);
    }

    public function test_co21_low_items(): void
    {
        $super = User::factory()->superAdmin()->create();

        // L1: no emoji icons anywhere in the views.
        foreach ($this->views() as $view) {
            $this->assertDoesNotMatchRegularExpression('/\x{1F512}/u', (string) file_get_contents($view), $view);
        }

        // L2: destructive buttons.
        $visitor = Visitor::factory()->create();
        $this->actingAs($super)->get("/staff/visitors/{$visitor->id}/delete")->assertSee('class="btn btn-danger">Move to trash</button>', false);

        // L4: error pages lead back.
        $this->actingAs($super)->get('/staff/visitors/999999')->assertNotFound()->assertSee('href="/staff"', false);
        $exhibition = Exhibition::factory()->create();
        $this->get('/r/'.$exhibition->public_token.'/nothing-here')->assertNotFound()->assertSee('href="/r/'.$exhibition->public_token.'"', false);

        // L5: the organisation on the sign-in page.
        app(Settings::class)->set('org_name_en', 'Sample Agri Company');
        auth()->logout();
        $this->get('/staff/login')->assertSeeInOrder(['<p class="org-line">Sample Agri Company</p>', '<h1 class="page-title">Sign in</h1>'], false);

        // L9: the closed page says what to do next.
        $closed = Exhibition::factory()->english()->closed()->create();
        $this->get('/r/'.$closed->public_token)->assertSee('Ask our team at the stand to register you.');

        // L14: ERP and notice colours come from tokens.
        $css = (string) file_get_contents(public_path('assets/app.css'));
        foreach (['#7a1f00', '#fbe3d9', '#b9dfc6', '#f0d49a', '#f2b8bb'] as $colour) {
            $this->assertSame(1, substr_count(strtolower($css), $colour), "{$colour} outside :root");
        }
    }

    public function test_co21_l12_logo_width_and_height_are_stored_at_upload(): void
    {
        $super = User::factory()->superAdmin()->create();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $base = ['org_name_en' => 'A', 'org_name_ar' => 'ب', 'brand_color' => '#1F4E79'];

        $this->actingAs($super)->put('/staff/settings/branding', [...$base, 'logo' => UploadedFile::fake()->createWithContent('l.png', $png)])->assertSessionHasNoErrors();
        $settings = app(Settings::class);
        $settings->forget();
        $this->assertSame([1, 1], [$settings->get('logo_width'), $settings->get('logo_height')]);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 240 80"><rect width="240" height="80"/></svg>';
        $this->actingAs($super)->put('/staff/settings/branding', [...$base, 'logo' => UploadedFile::fake()->createWithContent('l.svg', $svg)])->assertSessionHasNoErrors();
        $settings->forget();
        $this->assertSame([240, 80], [$settings->get('logo_width'), $settings->get('logo_height')]);
        $exhibition = Exhibition::factory()->create();
        $this->get('/r/'.$exhibition->public_token)->assertSee('width="240" height="80"', false);

        $this->actingAs($super)->put('/staff/settings/branding', [...$base, 'remove_logo' => '1'])->assertSessionHasNoErrors();
        $settings->forget();
        $this->assertNull($settings->get('logo_width'));
    }

    public function test_co22_send_wording_and_new_texts(): void
    {
        // No "push" or "pushing" in visible text of any view (Blade @push and route or permission names excluded).
        foreach ($this->views() as $view) {
            $text = (string) file_get_contents($view);
            $text = preg_replace(['/@(end)?push\b/', "/'[a-z_.-]*push[a-z_.-]*'/i", '/\$[a-zA-Z]*[Pp]ush[a-zA-Z]*/', '/PushService/', '/\{\{--.*?--\}\}/s'], '', $text);
            $this->assertDoesNotMatchRegularExpression('/\bpush(ing|ed)?\b/i', (string) $text, $view);
        }
        $this->assertDoesNotMatchRegularExpression('/\bpush(ing|ed)?\b/i', (string) file_get_contents(public_path('assets/entry.js')));

        $english = Exhibition::factory()->english()->create(['name' => 'Delta Farm Days']);
        $this->submitPublicForm($english, ['name' => 'Invented Visitor', 'phone' => '01001234567'])->assertRedirect();
        $this->get('/r/'.$english->public_token.'/thanks')->assertSee("You're registered for Delta Farm Days. Our team will contact you soon.");
        $this->assertStringContainsString('You were signed out. Sign in again in a new tab, then come back and press Retry. What you typed is still here.',
            (string) file_get_contents(public_path('assets/entry.js')));
    }
}
