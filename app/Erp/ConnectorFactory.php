<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Erp\Odoo\Odoo18JsonRpcConnector;
use App\Models\ErpConnection;
use App\Support\Settings;
use RuntimeException;

/**
 * Picks the connector for a connection (work order §8): the class comes from
 * the version selector through a registry, so a new Odoo version is one class
 * and one line here. The mock is used only with ERP_MOCK=true outside
 * production.
 */
class ConnectorFactory
{
    /** @var array<string, class-string<ErpLeadConnector>> */
    public const REGISTRY = [
        '18' => Odoo18JsonRpcConnector::class,
    ];

    public function __construct(private readonly Settings $settings) {}

    public function for(ErpConnection $connection): ErpLeadConnector
    {
        if (self::mockRequested()) {
            if (app()->environment('production')) {
                throw new RuntimeException('ERP_MOCK=true is refused when APP_ENV=production.');
            }

            return new MockConnector($connection);
        }

        $version = (string) $this->settings->get('odoo_version');
        $class = self::REGISTRY[$version] ?? throw new RuntimeException("No connector for Odoo version {$version}.");

        return new $class($connection);
    }

    public static function mockRequested(): bool
    {
        return (bool) config('exhibition.erp_mock');
    }

    /** True when ERP_MOCK is set in production: the System page shows it. */
    public static function mockRefused(): bool
    {
        return self::mockRequested() && app()->environment('production');
    }

    /**
     * @return list<string>
     */
    public static function versions(): array
    {
        return array_map('strval', array_keys(self::REGISTRY));
    }
}
