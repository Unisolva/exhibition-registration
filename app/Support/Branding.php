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

    public function logoUrl(): ?string
    {
        $logo = $this->settings->get('logo');
        $hash = $this->settings->get('logo_hash');
        if (! is_array($logo) || ! is_string($hash) || $hash === '') {
            return null;
        }

        return '/logo/'.$hash.'.'.($logo['mime'] === 'image/svg+xml' ? 'svg' : 'png');
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
