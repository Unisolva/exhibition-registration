<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Guards;

use PHPUnit\Framework\TestCase;
use Tests\Support\RepositoryFiles;

/**
 * Work order §0 and §16.2: every source file carries the Unisolva header near
 * its top. Files that cannot hold a comment (JSON, lock files) and prose
 * (Markdown, LICENSE) are exempt, as are binary files.
 */
class CopyrightHeaderTest extends TestCase
{
    public const HEADER = 'Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.';

    private const EXEMPT_EXTENSIONS = ['json', 'lock', 'md', 'png', 'ico', 'jpg', 'gif', 'woff', 'woff2'];

    private const EXEMPT_FILES = ['LICENSE'];

    public function test_every_source_file_starts_with_the_header(): void
    {
        $missing = [];
        foreach (RepositoryFiles::all() as $path) {
            if ($this->isExempt($path)) {
                continue;
            }

            $handle = fopen(RepositoryFiles::root().'/'.$path, 'rb');
            $top = '';
            for ($line = 0; $line < 5 && ($text = fgets($handle)) !== false; $line++) {
                $top .= $text;
            }
            fclose($handle);

            if (! str_contains($top, self::HEADER)) {
                $missing[] = $path;
            }
        }

        $this->assertSame([], $missing, 'Files without the copyright header in their first five lines.');
    }

    private function isExempt(string $path): bool
    {
        if (in_array(basename($path), self::EXEMPT_FILES, true)) {
            return true;
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXEMPT_EXTENSIONS, true);
    }
}
