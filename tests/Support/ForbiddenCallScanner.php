<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Support;

/**
 * Finds calls the hosting platform forbids (work order §2). Function calls are
 * found with PHP's tokenizer, so comments, strings, method calls ($x->exec()),
 * static calls (Foo::exec()) and declarations (function link()) never match.
 * This file holds the pattern list and lives in tests/, which is not scanned.
 */
final class ForbiddenCallScanner
{
    /** Global functions that must never be called. */
    public const FUNCTIONS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'symlink', 'link', 'show_source', 'dl',
    ];

    public const FUNCTION_PREFIXES = ['pcntl_'];

    /** Text patterns applied to the raw source of .php files. */
    public const PHP_TEXT_PATTERNS = [
        'Symfony Process' => '/Symfony\\\\Component\\\\Process/',
        'Process facade' => '/Illuminate\\\\Support\\\\Facades\\\\Process/',
        'Process:: call' => '/\bProcess::/',
        'schedule:run' => '/schedule:run/',
        'queue:work' => '/queue:work/',
        'storage:link' => '/storage:link/',
        'Laravel Prompts' => '/Laravel\\\\Prompts/',
        'Redis facade' => '/Illuminate\\\\Support\\\\Facades\\\\Redis|\bRedis::/',
    ];

    /** Patterns for views: no script, stylesheet or font from another host. */
    public const VIEW_PATTERNS = [
        'external script' => '/<script[^>]+src=["\']?(https?:)?\/\//i',
        'external stylesheet' => '/<link[^>]+href=["\']?(https?:)?\/\//i',
    ];

    /** Patterns for CSS files: no remote url() or @import. */
    public const CSS_PATTERNS = [
        'external url()' => '/url\(\s*["\']?(https?:)?\/\//i',
        'external @import' => '/@import\s+["\']?(url\()?\s*["\']?(https?:)?\/\//i',
    ];

    /**
     * @return list<array{name: string, line: int}>
     */
    public static function functionCalls(string $php): array
    {
        $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
        $tokens = array_values(array_filter(
            token_get_all($php),
            fn ($token) => ! is_array($token) || ! in_array($token[0], $ignored, true),
        ));

        $notAGlobalCall = [
            T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
            T_FUNCTION, T_NEW, T_CONST,
        ];

        $hits = [];
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = strtolower(ltrim($token[1], '\\'));
            if (! self::isForbiddenName($name)) {
                continue;
            }

            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            if (is_array($previous) && in_array($previous[0], $notAGlobalCall, true)) {
                continue;
            }

            $hits[] = ['name' => $name, 'line' => $token[2]];
        }

        return $hits;
    }

    /**
     * @param  array<string, string>  $patterns
     * @return list<string> names of the patterns that matched
     */
    public static function textMatches(string $text, array $patterns): array
    {
        $hits = [];
        foreach ($patterns as $label => $regex) {
            if (preg_match($regex, $text) === 1) {
                $hits[] = $label;
            }
        }

        return $hits;
    }

    private static function isForbiddenName(string $name): bool
    {
        if (in_array($name, self::FUNCTIONS, true)) {
            return true;
        }

        foreach (self::FUNCTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
