<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

/**
 * The running release: the version from config, and the release ID the
 * build writes into a RELEASE file (absent in a development checkout).
 */
class Release
{
    /**
     * @return array{version: string, id: string|null, commit: string|null}
     */
    public static function current(): array
    {
        $info = ['version' => (string) config('exhibition.version'), 'id' => null, 'commit' => null];
        $file = base_path('RELEASE');
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
                if (in_array($key, ['id', 'commit'], true)) {
                    $info[$key] = trim($value);
                }
            }
        }

        return $info;
    }
}
