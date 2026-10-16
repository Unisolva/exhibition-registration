<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Middleware;

use App\Models\Exhibition;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages (M8 decision 3): `?lang=` in the URL, then the visitor's
 * choice for this exhibition, then the exhibition's default language. A
 * choice is stored per exhibition token, so it never carries to another
 * exhibition. An unknown token gets no stored choice; its 404 is bilingual.
 */
class UsePublicLocale
{
    public const LOCALES = Exhibition::LOCALES;

    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    public static function apply(Request $request): string
    {
        $token = $request->route('token');
        $exhibition = is_string($token) ? Exhibition::findByPublicToken($token) : null;

        if ($exhibition === null) {
            $locale = in_array(config('app.locale'), self::LOCALES, true) ? config('app.locale') : 'ar';
            app()->setLocale($locale);

            return $locale;
        }

        $key = 'public_locale.'.$exhibition->public_token;
        $requested = $request->query('lang');
        if (is_string($requested) && in_array($requested, self::LOCALES, true)) {
            $request->session()->put($key, $requested);
        }

        $chosen = $request->session()->get($key);
        $locale = is_string($chosen) && in_array($chosen, self::LOCALES, true) ? $chosen : $exhibition->default_locale;
        app()->setLocale($locale);

        return $locale;
    }
}
