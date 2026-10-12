<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Guards;

use PHPUnit\Framework\TestCase;
use Tests\Support\RepositoryFiles;

/**
 * Work order §1a: no installation-specific value anywhere in the repository.
 *
 * The deny-list (client names, hostnames, server names) is never committed. It
 * comes from INSTALLATION_DENYLIST, comma-separated. Failure messages name the
 * file and the entry's position in the list, never the entry itself, because
 * CI logs of a public repository are public.
 */
class InstallationDenylistTest extends TestCase
{
    public function test_the_deny_list_is_provided(): void
    {
        $this->assertNotSame([], $this->terms(), 'INSTALLATION_DENYLIST is not set. Set it from the CI secret or the local deny-list file.');
    }

    public function test_no_deny_list_entry_appears_in_any_published_file(): void
    {
        $terms = $this->terms();
        $this->assertNotSame([], $terms, 'INSTALLATION_DENYLIST is not set.');

        $hits = [];
        foreach (RepositoryFiles::all() as $path) {
            $haystack = strtolower($path."\n".file_get_contents(RepositoryFiles::root().'/'.$path));

            foreach ($terms as $index => $term) {
                if (str_contains($haystack, $term)) {
                    $hits[] = $path.': deny-list entry #'.($index + 1);
                }
            }
        }

        $this->assertSame([], $hits, 'Installation-specific values found.');
    }

    /**
     * @return list<string>
     */
    private function terms(): array
    {
        $raw = getenv('INSTALLATION_DENYLIST');
        if (! is_string($raw)) {
            return [];
        }

        $terms = array_map(fn (string $term) => strtolower(trim($term)), explode(',', $raw));

        return array_values(array_filter($terms, fn (string $term) => $term !== ''));
    }
}
