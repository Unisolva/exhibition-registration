<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers;

use App\Support\Settings;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company logo, stored in the database and served under its content hash,
 * so it can be cached for a year and a new logo gets a new URL.
 */
class LogoController extends Controller
{
    public function __invoke(Settings $settings, string $hash, string $ext): Response
    {
        $logo = $settings->get('logo');
        abort_unless(is_array($logo) && $settings->get('logo_hash') === $hash, 404);

        $mime = $logo['mime'] === 'image/svg+xml' ? 'image/svg+xml' : 'image/png';
        abort_unless($ext === ($mime === 'image/svg+xml' ? 'svg' : 'png'), 404);

        return response((string) base64_decode($logo['data'], true), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Cacheable' => '1',
            // Opened on its own, an SVG still cannot run anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
