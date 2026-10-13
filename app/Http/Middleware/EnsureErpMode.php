<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With ERP mode "none" the push screens do not exist (work order §1a).
 */
class EnsureErpMode
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_if($this->settings->get('erp_mode') !== 'odoo', 404);

        return $next($request);
    }
}
