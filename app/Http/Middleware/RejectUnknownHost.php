<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers 404 to any request whose host is not the host of APP_URL, before
 * routing (work order §3: the app is also reachable through another site's
 * document root under that site's hostname).
 */
class RejectUnknownHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $actual = strtolower(preg_replace('/:\d+$/', '', (string) $request->headers->get('host', '')));

        if ($expected === '' || $actual !== $expected) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
