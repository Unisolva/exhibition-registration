<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use RuntimeException;

/**
 * Raised inside batch creation when no selected visitor is eligible, so the
 * empty batch is rolled back.
 */
class NothingToPushException extends RuntimeException
{
    public function __construct(public readonly int $skipped)
    {
        parent::__construct('Nothing to push.');
    }
}
