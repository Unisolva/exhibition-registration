<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Registration;

use Illuminate\Support\Facades\DB;

/**
 * M9 CO-7: one QR registration per phone number per exhibition. Two
 * submissions with the same number at the same moment take this short named
 * lock around the check and the insert, so the second one sees the first.
 */
class PhoneLock
{
    public const TIMEOUT_SECONDS = 5;

    /** At most 64 characters (MariaDB's limit for lock names). */
    public static function name(int $exhibitionId, string $phoneKey): string
    {
        return 'exreg-phone-'.sha1($exhibitionId.'|'.$phoneKey);
    }

    public static function acquire(string $name, int $timeout = self::TIMEOUT_SECONDS): bool
    {
        return (int) DB::selectOne('SELECT GET_LOCK(?, ?) AS got', [$name, $timeout])->got === 1;
    }

    public static function release(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }
}
