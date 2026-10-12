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

    private static function svgError(string $bytes): ?string
    {
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false) {
            return 'SVG files with a DOCTYPE or entities are not accepted.';
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        // No network access and no entity substitution while parsing.
        $loaded = $doc->loadXML($bytes, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

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
