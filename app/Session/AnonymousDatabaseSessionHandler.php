<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Laravel's database session store without the client IP address and user
 * agent columns filled: public visitors have sessions too, and the app stores
 * no IP addresses (work order §4).
 */
class AnonymousDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function addRequestInformation(&$payload)
    {
        $payload['ip_address'] = null;
        $payload['user_agent'] = null;

        return $this;
    }
}
