<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp\Odoo;

use App\Erp\ConnectionReport;
use App\Erp\ErpException;
use App\Erp\ErpLeadConnector;
use App\Models\ErpConnection;

/**
 * Odoo 18 over JSON-RPC (work order §8). Implemented and verified against the
 * UAT server in milestone M5; until then every call fails as "unknown", so a
 * build without it can never push to a real ERP.
 */
class Odoo18JsonRpcConnector implements ErpLeadConnector
{
    public function __construct(private readonly ErpConnection $connection) {}

    public function serverVersion(): string
    {
        throw $this->notAvailable();
    }

    public function findByReference(string $ref): array
    {
        throw $this->notAvailable();
    }

    public function createLead(array $payload): int
    {
        throw $this->notAvailable();
    }

    public function resolveCountry(string $countryCode): ?int
    {
        throw $this->notAvailable();
    }

    public function resolveRegion(string $countryCode, string $erpCode): ?int
    {
        throw $this->notAvailable();
    }

    public function testConnection(string $expectedMajorVersion): ConnectionReport
    {
        return ConnectionReport::failed(ConnectionReport::STEP_VERSION, 'The Odoo 18 connector is not available in this build.');
    }

    private function notAvailable(): ErpException
    {
        return new ErpException(ErpException::UNKNOWN, 'The Odoo 18 connector is not available in this build.');
    }
}
