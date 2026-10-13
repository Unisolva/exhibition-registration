<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use RuntimeException;

/**
 * A change was refused because the visitor is locked (answered with 423).
 */
class VisitorLockedException extends RuntimeException
{
    public function __construct(public readonly string $cause)
    {
        parent::__construct(VisitorLock::message($cause));
    }
}
