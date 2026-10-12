<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Http;

use App\Http\Middleware\AssignRequestId;
use App\Support\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work order §6 headers, §3 wrong-host refusal, §9 request ID and error pages.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_never_cacheable_and_carry_a_fresh_csp_nonce(): void
    {
        $first = $this->get('/staff/login')->assertOk();
        $this->newProcess();
        $second = $this->get('/staff/login')->assertOk();

        foreach ([$first, $second] as $response) {
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
            $csp = $response->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]{24}'/", $csp);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        }

        $this->assertNotSame(
            $first->headers->get('Content-Security-Policy'),
            $second->headers->get('Content-Security-Policy'),
        );
    }

    public function test_every_response_has_a_ulid_request_id(): void
    {
        $a = $this->get('/staff/login')->headers->get(AssignRequestId::HEADER);
        $b = $this->get('/does-not-exist')->headers->get(AssignRequestId::HEADER);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $a);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_a_request_for_another_host_gets_404_before_routing(): void
    {
        $this->get('http://other-site.example/staff/login')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('http://sub.localhost/staff/login')->assertNotFound();

        // A path under another site's document root, as reached through that site's hostname.
        $this->get('http://other-site.example/exhibitions.example.test/staff/login')->assertNotFound();

        $this->get('http://localhost/staff/login')->assertOk();
    }

    public function test_error_pages_show_both_languages(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('الصفحة غير موجودة')
            ->assertSee('Page not found')
            ->assertDontSee('Laravel');
    }

    public function test_sessions_store_no_ip_address_or_user_agent(): void
    {
        $this->withHeaders(['User-Agent' => 'ProbeBrowser/1.0'])->get('/staff/login')->assertOk();

        $row = DB::table('sessions')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_agent);
    }

    public function test_versioned_assets_are_served_with_long_caching_and_wrong_hashes_are_404(): void
    {
        $url = Asset::url('app.css');
        $this->assertMatchesRegularExpression('#^/assets/app\.[0-9a-f]{10}\.css$#', $url);

        $response = $this->get($url)->assertOk();
        $this->assertStringStartsWith('text/css', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('X-Cacheable'));

        $this->get('/assets/app.0000000000.css')->assertNotFound();
    }

    public function test_robots_are_told_to_stay_out(): void
    {
        $this->assertStringContainsString("Disallow: /\n", file_get_contents(public_path('robots.txt')));
    }
}
