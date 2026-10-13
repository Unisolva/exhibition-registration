<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

/**
 * Work order §8 "Connector interface", plus resolveCountry() (§16.4) and the
 * unauthenticated version read the drain and the connection test use (§7).
 * Every method throws ErpException with an error class on failure.
 */
interface ErpLeadConnector
{
    /** The server's version string, read without logging in. */
    public function serverVersion(): string;

    /**
     * Lead IDs whose reference marker matches, archived leads included.
     *
     * @return list<int>
     */
    public function findByReference(string $ref): array;

    /**
     * @param  array<string, mixed>  $payload  lead values, with country_id/state_id already resolved
     */
    public function createLead(array $payload): int;

    public function resolveCountry(string $countryCode): ?int;

    public function resolveRegion(string $countryCode, string $erpCode): ?int;

    public function testConnection(string $expectedMajorVersion): ConnectionReport;
}
