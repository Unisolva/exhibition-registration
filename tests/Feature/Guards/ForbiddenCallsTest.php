<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Guards;

use Illuminate\Support\Facades\Blade;
use Tests\Support\ForbiddenCallScanner;
use Tests\Support\RepositoryFiles;
use Tests\TestCase;

/**
 * Work order §2: no shell or process functions, no scheduler or queue worker,
 * no interactive prompts, no Redis, no CDN. Stock config/ files are not scanned;
 * RuntimeConfigTest checks the effective configuration instead.
 */
class ForbiddenCallsTest extends TestCase
{
    private const SCANNED_DIRECTORIES = ['app/', 'routes/', 'resources/', 'database/', 'bootstrap/', 'public/'];

    public function test_no_forbidden_function_is_called(): void
    {
        $hits = [];
        foreach ($this->phpFiles() as $path) {
            $source = file_get_contents(base_path($path));
            $code = str_ends_with($path, '.blade.php') ? Blade::compileString($source) : $source;

            foreach (ForbiddenCallScanner::functionCalls($code) as $hit) {
                $hits[] = "{$path}: {$hit['name']}() near line {$hit['line']}";
            }
        }

        $this->assertNotEmpty($this->phpFiles(), 'The scan found no PHP files; the file list is wrong.');
        $this->assertSame([], $hits, 'Forbidden function calls found.');
    }

    public function test_no_process_scheduler_worker_prompt_or_redis_use(): void
    {
        $hits = [];
        foreach ($this->phpFiles() as $path) {
            foreach (ForbiddenCallScanner::textMatches(file_get_contents(base_path($path)), ForbiddenCallScanner::PHP_TEXT_PATTERNS) as $label) {
                $hits[] = "{$path}: {$label}";
            }
        }

        $this->assertSame([], $hits, 'Forbidden classes or commands referenced.');
    }

    public function test_views_and_stylesheets_load_nothing_from_another_host(): void
    {
        $hits = [];
        foreach ($this->scannedFiles() as $path) {
            $patterns = match (true) {
                str_ends_with($path, '.blade.php'), str_ends_with($path, '.html') => ForbiddenCallScanner::VIEW_PATTERNS,
                str_ends_with($path, '.css') => ForbiddenCallScanner::CSS_PATTERNS,
                default => [],
            };

            foreach (ForbiddenCallScanner::textMatches(file_get_contents(base_path($path)), $patterns) as $label) {
                $hits[] = "{$path}: {$label}";
            }
        }

        $this->assertSame([], $hits, 'Remote scripts, stylesheets or fonts referenced.');
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        return array_values(array_filter($this->scannedFiles(), fn (string $path) => str_ends_with($path, '.php')));
    }

    /**
     * @return list<string>
     */
    private function scannedFiles(): array
    {
        return array_values(array_filter(RepositoryFiles::all(), function (string $path): bool {
            foreach (self::SCANNED_DIRECTORIES as $directory) {
                if (str_starts_with($path, $directory)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
