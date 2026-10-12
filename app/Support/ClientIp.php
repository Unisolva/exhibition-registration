<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The client address used for rate limits (work order §9). REMOTE_ADDR as the
 * web server gives it; forwarding headers are ignored unless the installation
 * opts in, and even then only from Cloudflare's published ranges.
 */
class ClientIp
{
    public static function of(Request $request): string
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');

        if (config('exhibition.trust_cloudflare_header')
            && IpUtils::checkIp($remote, config('exhibition.cloudflare_ranges'))) {
            $forwarded = trim((string) $request->header('CF-Connecting-IP', ''));
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                return $forwarded;
            }
        }

        return $remote;
    }
}
