<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

/**
 * The installation's brand on public pages (work order §1a): its name, logo
 * and colour. The only Unisolva mark is the optional "Powered by" link.
 */
class Branding
{
    public const MIN_CONTRAST = 4.5;

    /** M11 CO-1: the two text colours a gift box can take. */
    public const INK_DARK = '#1B1F24';

    public const INK_LIGHT = '#FFFFFF';

    public function __construct(private readonly Settings $settings) {}

    public function orgName(?string $locale = null): string
    {
        $en = (string) $this->settings->get('org_name_en');
        $ar = (string) $this->settings->get('org_name_ar');
        $name = ($locale ?? app()->getLocale()) === 'ar' ? ($ar ?: $en) : ($en ?: $ar);

        return $name !== '' ? $name : (string) config('app.name');
    }

    public function color(): string
    {
        $color = (string) $this->settings->get('brand_color');

        return self::contrastWithWhite($color) >= self::MIN_CONTRAST ? strtoupper($color) : Settings::DEFAULTS['brand_color'];
    }

    /**
     * M11 CO-1: the accent colour and the text colour chosen for it, or null
     * when none is set (or a stored value no longer passes the check).
     *
     * @return array{color: string, ink: string}|null
     */
    public function accent(): ?array
    {
        $color = $this->settings->get('accent_color');
        if (! is_string($color) || $color === '') {
            return null;
        }
        [$ink, $ratio] = self::inkFor($color);

        return $ratio >= self::MIN_CONTRAST ? ['color' => strtoupper($color), 'ink' => $ink] : null;
    }

    /**
     * Dark ink or white, whichever contrasts more with the colour, and that ratio.
     *
     * @return array{0: string, 1: float}
     */
    public static function inkFor(string $hex): array
    {
        $dark = self::contrast($hex, self::INK_DARK);
        $light = self::contrast($hex, self::INK_LIGHT);

        return $dark >= $light ? [self::INK_DARK, $dark] : [self::INK_LIGHT, $light];
    }

    /** WCAG contrast ratio of two #RRGGBB colours; 0 when either is not a valid hex colour. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);
        if ($la === null || $lb === null) {
            return 0.0;
        }

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): ?float
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            return null;
        }
        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);
    }

    public function logoUrl(): ?string
    {
        $logo = $this->settings->get('logo');
        $hash = $this->settings->get('logo_hash');
        if (! is_array($logo) || ! is_string($hash) || $hash === '') {
            return null;
        }

        return '/logo/'.$hash.'.'.($logo['mime'] === 'image/svg+xml' ? 'svg' : 'png');
    }

    /**
     * The logo's stored width and height, or null for a logo uploaded before
     * 1.1.0 (it renders without them until it is uploaded again).
     *
     * @return array{0: int, 1: int}|null
     */
    public function logoSize(): ?array
    {
        $width = $this->settings->get('logo_width');
        $height = $this->settings->get('logo_height');

        return $this->logoUrl() !== null && is_int($width) && is_int($height) && $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    public function showPoweredBy(): bool
    {
        return (bool) $this->settings->get('show_powered_by');
    }

    public function privacyUrl(): ?string
    {
        $url = $this->settings->get('privacy_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * WCAG contrast ratio of white text on the given #RRGGBB colour; 0 when the
     * colour is not a valid hex colour.
     */
    public static function contrastWithWhite(string $hex): float
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            return 0.0;
        }

        $channel = function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel($m[1]) + 0.7152 * $channel($m[2]) + 0.0722 * $channel($m[3]);

        return 1.05 / ($luminance + 0.05);
    }
}
