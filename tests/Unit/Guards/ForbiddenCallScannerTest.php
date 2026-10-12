<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Unit\Guards;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ForbiddenCallScanner;

/**
 * Proves the scanner finds real calls and ignores look-alikes, so the
 * repository scan can be trusted both ways.
 */
class ForbiddenCallScannerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function forbiddenCalls(): array
    {
        return [
            'exec' => ['<?php exec("ls");', 'exec'],
            'fully qualified' => ['<?php \\shell_exec("ls");', 'shell_exec'],
            'space before paren' => ['<?php system ("ls");', 'system'],
            'upper case' => ['<?php PassThru("ls");', 'passthru'],
            'proc_open' => ['<?php $p = proc_open("ls", [], $pipes);', 'proc_open'],
            'popen' => ['<?php popen("ls", "r");', 'popen'],
            'symlink' => ['<?php symlink("a", "b");', 'symlink'],
            'link' => ['<?php link("a", "b");', 'link'],
            'pcntl' => ['<?php pcntl_fork();', 'pcntl_fork'],
            'first-class callable' => ['<?php $f = exec(...);', 'exec'],
        ];
    }

    #[DataProvider('forbiddenCalls')]
    public function test_it_finds_forbidden_calls(string $code, string $expected): void
    {
        $hits = ForbiddenCallScanner::functionCalls($code);

        $this->assertCount(1, $hits);
        $this->assertSame($expected, $hits[0]['name']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lookAlikes(): array
    {
        return [
            'PDO method' => ['<?php $pdo->exec("SET NAMES utf8mb4");'],
            'nullsafe method' => ['<?php $x?->system();'],
            'static method' => ['<?php Foo::link("a");'],
            'method declaration' => ['<?php class A { public function link() {} }'],
            'variable call' => ['<?php $link("a");'],
            'string' => ['<?php $s = "exec(1)";'],
            'line comment' => ['<?php // exec("ls")'],
            'block comment' => ['<?php /* system("ls") */'],
            'bare word' => ['<?php $system = 1; echo "operating system";'],
            'longer name' => ['<?php links(); execute(); systemd();'],
            'namespaced function' => ['<?php App\\Support\\link("a");'],
            'new object' => ['<?php new system();'],
        ];
    }

    #[DataProvider('lookAlikes')]
    public function test_it_ignores_look_alikes(string $code): void
    {
        $this->assertSame([], ForbiddenCallScanner::functionCalls($code));
    }

    public function test_text_patterns_match_process_and_artisan_commands(): void
    {
        $php = '<?php use Symfony\\Component\\Process\\Process; Artisan::call("schedule:run");';

        $hits = ForbiddenCallScanner::textMatches($php, ForbiddenCallScanner::PHP_TEXT_PATTERNS);

        $this->assertContains('Symfony Process', $hits);
        $this->assertContains('schedule:run', $hits);
    }

    public function test_view_patterns_match_only_remote_assets(): void
    {
        $patterns = ForbiddenCallScanner::VIEW_PATTERNS;

        $this->assertSame(['external script'], ForbiddenCallScanner::textMatches('<script src="https://cdn.example.com/x.js"></script>', $patterns));
        $this->assertSame(['external stylesheet'], ForbiddenCallScanner::textMatches('<link rel="stylesheet" href="//fonts.example.com/f.css">', $patterns));
        $this->assertSame([], ForbiddenCallScanner::textMatches('<script src="/assets/app.js"></script><a href="https://example.com">x</a>', $patterns));
    }

    public function test_css_patterns_match_only_remote_urls(): void
    {
        $patterns = ForbiddenCallScanner::CSS_PATTERNS;

        $this->assertNotSame([], ForbiddenCallScanner::textMatches('body{background:url("https://example.com/a.png")}', $patterns));
        $this->assertNotSame([], ForbiddenCallScanner::textMatches('@import url(//fonts.example.com/f.css);', $patterns));
        $this->assertSame([], ForbiddenCallScanner::textMatches('body{background:url(/assets/a.png)}', $patterns));
    }
}
