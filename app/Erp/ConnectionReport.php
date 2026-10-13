<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

/**
 * Result of a connection test (work order §7): success with the Odoo user ID
 * and server version, or the first step that failed.
 */
final class ConnectionReport
{
    public const STEP_VERSION = 'version';

    public const STEP_AUTHENTICATE = 'authenticate';

    public const STEP_FIELDS = 'fields';

    public const STEP_RIGHTS = 'rights';

    public function __construct(
        public readonly bool $ok,
        public readonly ?string $failedStep,
        public readonly string $message,
        public readonly ?string $serverVersion = null,
        public readonly ?int $uid = null,
    ) {}

    public static function passed(string $serverVersion, int $uid): self
    {
        return new self(true, null, "Connected as Odoo user {$uid}, server version {$serverVersion}.", $serverVersion, $uid);
    }

    public static function failed(string $step, string $message, ?string $serverVersion = null): self
    {
        return new self(false, $step, $message, $serverVersion);
    }
}
