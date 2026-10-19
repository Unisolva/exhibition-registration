<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Welcome-package codes (M9 CO-5): 6 Crockford characters from random_bytes,
 * unique across all visitors. Every visitor gets one at insert; older rows get
 * one from backfill(), run by the migration and by ProductSeeder on each
 * deploy, which never overwrites an existing code.
 */
class PackageCode
{
    public const LENGTH = 6;

    public static function generate(): string
    {
        do {
            $code = Crockford::random(self::LENGTH);
        } while (DB::table('visitors')->where('package_code', $code)->exists());

        return $code;
    }

    /** Gives every visitor without a code one. Returns how many were filled. */
    public static function backfill(): int
    {
        $filled = 0;
        DB::table('visitors')->whereNull('package_code')->orderBy('id')->select('id')
            ->chunkById(500, function ($rows) use (&$filled) {
                foreach ($rows as $row) {
                    $filled += DB::table('visitors')->where('id', $row->id)->whereNull('package_code')
                        ->update(['package_code' => self::generate()]);
                }
            });

        return $filled;
    }

    /** A typed code read as Crockford does (case, 0/O, 1/I/L), or null when it cannot be one. */
    public static function normalize(string $input): ?string
    {
        $code = Crockford::normalize(trim($input));

        return preg_match('/^['.Crockford::ALPHABET.']{'.self::LENGTH.'}$/', $code) === 1 ? $code : null;
    }
}
