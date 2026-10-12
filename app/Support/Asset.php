<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

/**
 * Versioned asset URLs without a build step: public/assets/app.css is served
 * as /assets/app.<first 10 hex of its SHA-1>.css, so a new release never meets
 * a stale cached copy.
 */
class Asset
{
    /** @var array<string, string> */
    private static array $hashes = [];

    public static function url(string $file): string
    {
        $path = public_path('assets/'.$file);
        self::$hashes[$file] ??= substr((string) sha1_file($path), 0, 10);

        $dot = strrpos($file, '.');

        return '/assets/'.substr($file, 0, $dot).'.'.self::$hashes[$file].substr($file, $dot);
    }

    /**
     * The real file behind a versioned name, or null when the name or hash does not match.
     */
    public static function resolve(string $versioned): ?string
    {
        if (preg_match('/^([a-z0-9-]+)\.([0-9a-f]{10})\.(css|js)$/', $versioned, $m) !== 1) {
            return null;
        }

        $path = public_path('assets/'.$m[1].'.'.$m[3]);
        if (! is_file($path) || substr((string) sha1_file($path), 0, 10) !== $m[2]) {
            return null;
        }

        return $path;
    }
}
