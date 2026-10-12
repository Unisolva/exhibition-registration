<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

/**
 * Random tokens in Crockford base32 (no I, L, O, U), from random_bytes.
 */
class Crockford
{
    public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function random(int $length): string
    {
        $bytes = random_bytes((int) ceil($length * 5 / 8) + 1);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $token .= self::ALPHABET[bindec(substr($bits, $i * 5, 5))];
        }

        return $token;
    }

    /** Uppercase, and read the look-alike letters the way Crockford base32 defines them. */
    public static function normalize(string $input): string
    {
        return strtr(strtoupper($input), ['I' => '1', 'L' => '1', 'O' => '0']);
    }
}
