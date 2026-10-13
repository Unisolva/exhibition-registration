<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

/*
 * Product configuration that is not a database setting. Everything specific to
 * one installation is a setting (edited in the app) or a .env value.
 */
return [

    // Developer-only mock ERP (work order §1a). Refused when APP_ENV=production; never shown in the UI.
    'erp_mock' => (bool) env('ERP_MOCK', false),

    // Initial value of the default_country setting on a fresh installation.
    'default_country' => env('DEFAULT_COUNTRY', 'EG'),

    // Read CF-Connecting-IP, but only when REMOTE_ADDR is one of Cloudflare's
    // published ranges below. Off by default (work order §9).
    'trust_cloudflare_header' => (bool) env('TRUST_CLOUDFLARE_HEADER', false),

    // https://www.cloudflare.com/ips/ (checked 12 Oct 2026)
    'cloudflare_ranges' => [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ],

];
