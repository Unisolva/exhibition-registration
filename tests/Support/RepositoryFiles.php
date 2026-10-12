<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The files that a commit would publish: tracked files plus untracked files
 * that .gitignore does not exclude. Paths are relative to the repository root.
 */
final class RepositoryFiles
{
    /** @var list<string>|null */
    private static ?array $cache = null;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $root = dirname(__DIR__, 2);
        $process = new Process(
            ['git', '-c', 'safe.directory=*', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'],
            $root,
        );
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files failed: '.$process->getErrorOutput());
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $path) {
            if ($path === '' || ! is_file($root.'/'.$path)) {
                continue;
            }
            $files[$path] = true;
        }

        $list = array_keys($files);
        sort($list);

        return self::$cache = $list;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
