<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages: Arabic by default, English via ?lang=en, remembered in the
 * session (decision D3).
 */
class UsePublicLocale
{
    public const LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    public static function apply(Request $request): string
    {
        $requested = $request->query('lang');
        if (is_string($requested) && in_array($requested, self::LOCALES, true)) {
            $request->session()->put('public_locale', $requested);
        }

        $default = in_array(config('app.locale'), self::LOCALES, true) ? config('app.locale') : 'ar';
        $locale = $request->session()->get('public_locale', $default);
        app()->setLocale($locale);

        return $locale;
    }
}
