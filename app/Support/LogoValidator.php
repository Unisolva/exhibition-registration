<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

use DOMDocument;
use DOMElement;

/**
 * Work order §1a: a logo is a PNG or SVG of at most 100 KB. An SVG holding a
 * script, an event attribute or any reference outside itself is rejected.
 */
class LogoValidator
{
    public const MAX_BYTES = 102400;

    public const DOCTYPE_ERROR = 'SVG files with a DOCTYPE or entities are not accepted.';

    public const ENCODING_ERROR = 'The SVG file must be UTF-8 encoded.';

    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** Elements that can run code, embed other documents or pull external content. */
    private const FORBIDDEN_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'animate', 'set', 'handler', 'listener'];

    /**
     * @return array{mime: string}|string the detected type, or an error message
     */
    public static function check(string $bytes): array|string
    {
        if (strlen($bytes) === 0) {
            return 'The file is empty.';
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return 'The logo must be 100 KB or smaller.';
        }

        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            $info = @getimagesizefromstring($bytes);

            return ($info !== false && $info[2] === IMAGETYPE_PNG) ? ['mime' => 'image/png'] : 'The PNG file is damaged.';
        }

        $error = self::svgError($bytes);

        return $error === null ? ['mime' => 'image/svg+xml'] : $error;
    }

    /**
     * Intrinsic size of a logo that passed check(), for the img width and height
     * (M8 L12): a PNG from its header, an SVG from its viewBox, else its width
     * and height attributes. Null when the file does not say.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function dimensions(string $bytes, string $mime): ?array
    {
        if ($mime === 'image/png') {
            $info = @getimagesizefromstring($bytes);

            return $info !== false && $info[0] > 0 && $info[1] > 0 ? [(int) $info[0], (int) $info[1]] : null;
        }

        $dom = new DOMDocument;
        if (! @$dom->loadXML($bytes, LIBXML_NONET) || $dom->documentElement === null) {
            return null;
        }
        $root = $dom->documentElement;
        $numbers = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox')), -1, PREG_SPLIT_NO_EMPTY);
        if (count($numbers) === 4 && is_numeric($numbers[2]) && is_numeric($numbers[3])) {
            [$width, $height] = [(float) $numbers[2], (float) $numbers[3]];
        } else {
            $width = (float) $root->getAttribute('width');
            $height = (float) $root->getAttribute('height');
        }

        return $width > 0 && $height > 0 ? [max(1, (int) round($width)), max(1, (int) round($height))] : null;
    }

    private static function svgError(string $bytes): ?string
    {
        // The byte search below is only meaningful if libxml reads the same bytes:
        // UTF-8 only, so UTF-16/32 or a declared UTF-7 cannot hide a DOCTYPE.
        if (str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            return self::ENCODING_ERROR;
        }
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*<\?xml[^>]*\bencoding\s*=\s*["\']([^"\']*)["\']/i', $bytes, $m) === 1
            && ! in_array(strtolower($m[1]), ['utf-8', 'utf8', 'us-ascii'], true)) {
            return self::ENCODING_ERROR;
        }

        // No DOCTYPE and no entity declarations at all: no entity expansion, internal or external.
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) {
            return self::DOCTYPE_ERROR;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        // No network access while parsing (entities cannot occur after the checks above).
        $loaded = $doc->loadXML($bytes, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded && $doc->doctype !== null) {
            return self::DOCTYPE_ERROR;
        }

        if (! $loaded || $doc->documentElement === null || strtolower($doc->documentElement->localName) !== 'svg') {
            return 'Upload a PNG or SVG file.';
        }

        foreach ($doc->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            if (in_array(strtolower($element->localName), self::FORBIDDEN_ELEMENTS, true)) {
                return 'The SVG contains a script or embedded content.';
            }

            if (strtolower($element->localName) === 'style' && self::cssIsUnsafe($element->textContent)) {
                return 'The SVG style refers to an external resource.';
            }

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->localName);
                $value = trim($attribute->value);

                if (str_starts_with($name, 'on')) {
                    return 'The SVG contains an event attribute.';
                }
                if (in_array($name, ['href', 'src'], true) && $value !== '' && ! str_starts_with($value, '#')) {
                    return 'The SVG refers to an external resource.';
                }
                // style="…" and presentation attributes such as fill="url(…)" may only point inside the file.
                if (self::cssIsUnsafe($value)) {
                    return 'The SVG style refers to an external resource.';
                }
                if (preg_match('/^\s*(javascript|vbscript|data):/i', $value) === 1) {
                    return 'The SVG contains a script.';
                }
            }
        }

        return null;
    }

    private static function cssIsUnsafe(string $css): bool
    {
        return stripos($css, '@import') !== false
            || stripos($css, 'javascript:') !== false
            || preg_match('/url\(\s*["\']?(?!#)/i', $css) === 1;
    }
}
