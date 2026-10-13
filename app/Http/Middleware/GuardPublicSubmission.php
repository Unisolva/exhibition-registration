<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Middleware;

use App\Http\Controllers\PublicRegistrationController;
use App\Support\ClientIp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Work order §6 POST order, step 1: size cap and rate limits, before the CSRF
 * check. Sits in the web group between session start and CSRF, and acts only
 * on the public form submission.
 */
class GuardPublicSubmission
{
    public const MAX_BODY_BYTES = 16384;

    public const PER_CLIENT_PER_MINUTE = 20;

    public const PER_EXHIBITION_PER_MINUTE = 600;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('public.submit', 'staff.registrations.store')) {
            return $next($request);
        }

        // Declared length, raw body, and the parsed fields (a chunked request has no Content-Length).
        // The cap applies to staff entry too (work order §7); the rate limits only to the public form.
        if ((int) $request->header('Content-Length', '0') > self::MAX_BODY_BYTES
            || strlen((string) $request->getContent()) > self::MAX_BODY_BYTES
            || strlen(http_build_query($request->request->all())) > self::MAX_BODY_BYTES) {
            abort(413);
        }

        if (! $request->routeIs('public.submit')) {
            return $next($request);
        }

        $token = strtoupper((string) $request->route('token'));
        $perClient = 'public-submit:'.ClientIp::of($request).'|'.$token;
        $perExhibition = 'public-submit-exhibition:'.$token;

        if (RateLimiter::tooManyAttempts($perClient, self::PER_CLIENT_PER_MINUTE)
            || RateLimiter::tooManyAttempts($perExhibition, self::PER_EXHIBITION_PER_MINUTE)) {
            return app(PublicRegistrationController::class)->rateLimited($request, $token);
        }

        RateLimiter::hit($perClient, 60);
        RateLimiter::hit($perExhibition, 60);

        return $next($request);
    }
}
