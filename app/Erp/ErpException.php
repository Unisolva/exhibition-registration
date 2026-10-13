<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use RuntimeException;

/**
 * A failed ERP call with its error class (work order §8 step 6). The message
 * is already sanitised: no payload, no credentials, at most 255 characters.
 */
class ErpException extends RuntimeException
{
    public const NETWORK = 'network';

    public const TIMEOUT = 'timeout';

    public const HTTP_5XX = 'http_5xx';

    public const RATE_LIMITED = 'rate_limited';

    public const AUTH = 'auth';

    public const VALIDATION = 'validation';

    public const UNKNOWN = 'unknown';

    /** Classes worth retrying on the drain's schedule. */
    public const TRANSIENT = [self::NETWORK, self::TIMEOUT, self::HTTP_5XX, self::RATE_LIMITED];

    public function __construct(public readonly string $errorClass, string $message = '')
    {
        parent::__construct(mb_substr($message !== '' ? $message : $errorClass, 0, 255));
    }

    public function isTransient(): bool
    {
        return in_array($this->errorClass, self::TRANSIENT, true);
    }
}
