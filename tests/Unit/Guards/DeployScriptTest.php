<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Unit\Guards;

use PHPUnit\Framework\TestCase;

/**
 * Lessons from the first deploy on cPanel/CloudLinux, kept as checks on the
 * release scripts:
 * - CageFS has no /dev/fd, so process substitution "<(...)" fails there.
 * - The web server reads static files as another user, so a release must be
 *   755/644; a restrictive umask or an archive root of 700 makes the site 404.
 */
class DeployScriptTest extends TestCase
{
    private static function script(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/bin/'.$name);
    }

    public function test_the_scripts_use_no_process_substitution(): void
    {
        foreach (['deploy.sh', 'build-release.sh'] as $name) {
            $code = implode("\n", array_filter(explode("\n", self::script($name)), fn ($line) => ! str_starts_with(ltrim($line), '#')));
            $this->assertDoesNotMatchRegularExpression('/<\(/', $code, "{$name} uses process substitution");
            $this->assertStringNotContainsString('mapfile', $code, "{$name} uses mapfile with a pipe source");
        }
    }

    public function test_deploy_leaves_the_release_readable_by_the_web_server(): void
    {
        $deploy = self::script('deploy.sh');

        $this->assertMatchesRegularExpression('/^umask 022$/m', $deploy);
        $this->assertStringContainsString('find "$NEW" -type d -exec chmod 755 {} +', $deploy);
        $this->assertStringContainsString('find "$NEW" -type f -exec chmod 644 {} +', $deploy);
        $this->assertStringContainsString('chmod 755 "$NEW"', $deploy);
    }

    public function test_the_build_does_not_carry_a_700_archive_root(): void
    {
        $this->assertStringContainsString('chmod 755 "$WORK"', self::script('build-release.sh'));
    }
}
