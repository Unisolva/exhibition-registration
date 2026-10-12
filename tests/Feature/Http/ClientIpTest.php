<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Http;

use App\Support\ClientIp;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Work order §9: REMOTE_ADDR as given; CF-Connecting-IP only when the switch is
 * on and the connection comes from Cloudflare's ranges.
 */
class ClientIpTest extends TestCase
{
    private function request(string $remote, array $headers = []): Request
    {
        $server = ['REMOTE_ADDR' => $remote];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'GET', server: $server);
    }

    public function test_forwarding_headers_are_ignored_by_default(): void
    {
        $request = $this->request('203.0.113.5', ['X-Forwarded-For' => '198.51.100.1', 'CF-Connecting-IP' => '198.51.100.2']);

        $this->assertSame('203.0.113.5', ClientIp::of($request));
        $this->assertSame('203.0.113.5', $request->ip());
    }

    public function test_cloudflare_header_is_read_only_from_cloudflare_ranges_when_enabled(): void
    {
        config(['exhibition.trust_cloudflare_header' => true]);

        $this->assertSame('198.51.100.2', ClientIp::of($this->request('162.158.1.1', ['CF-Connecting-IP' => '198.51.100.2'])));
        $this->assertSame('203.0.113.5', ClientIp::of($this->request('203.0.113.5', ['CF-Connecting-IP' => '198.51.100.2'])));
        $this->assertSame('162.158.1.1', ClientIp::of($this->request('162.158.1.1', ['CF-Connecting-IP' => 'not-an-ip'])));
    }
}
