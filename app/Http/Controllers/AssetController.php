<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers;

use App\Support\Asset;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a versioned asset when the web server's rewrite did not (local
 * development server). Production serves these files without PHP.
 */
class AssetController extends Controller
{
    public function __invoke(string $file): Response
    {
        $path = Asset::resolve($file);
        abort_if($path === null, 404);

        $type = str_ends_with($path, '.css') ? 'text/css; charset=utf-8' : 'text/javascript; charset=utf-8';

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Cacheable' => '1',
        ]);
    }
}
