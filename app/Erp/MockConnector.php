<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Models\ErpConnection;
use Illuminate\Support\Facades\Cache;

/**
 * Deterministic stand-in for Odoo (work order §8), developer-only. Its state
 * (the "remote" leads and the scripted outcomes) lives in the application
 * cache under a cache lock, so drains in separate processes share it.
 *
 * Scripts are per operation (version, search, create, test) and consumed one
 * entry per call; an empty script means success. Entries: ok, network,
 * timeout, http_5xx, rate_limited, auth, validation, unknown, and for create
 * also timeout_after (the lead is created, then the call times out).
 */
class MockConnector implements ErpLeadConnector
{
    private const STATE_KEY = 'mock-erp:state';

    private const LOCK_KEY = 'mock-erp:lock';

    public function __construct(private readonly ErpConnection $connection) {}

    // ---- test hooks -------------------------------------------------------

    public static function reset(): void
    {
        Cache::forever(self::STATE_KEY, self::initialState());
    }

    /**
     * @param  list<string>  $outcomes
     */
    public static function script(string $operation, array $outcomes): void
    {
        self::mutate(function (array &$state) use ($operation, $outcomes) {
            $state['scripts'][$operation] = $outcomes;
        });
    }

    public static function setServerVersion(string $version): void
    {
        self::mutate(fn (array &$state) => $state['server_version'] = $version);
    }

    public static function setDelayMs(int $ms): void
    {
        self::mutate(fn (array &$state) => $state['delay_ms'] = $ms);
    }

    /** Put a lead into the mock ERP directly, as if someone created it there. */
    public static function seedLead(string $description): int
    {
        return self::mutate(function (array &$state) use ($description) {
            $id = $state['next_id']++;
            $state['leads'][$id] = ['description' => $description, 'payload' => []];

            return $id;
        });
    }

    /**
     * @return array<int, array{description: string, payload: array<string, mixed>}>
     */
    public static function leads(): array
    {
        return self::state()['leads'];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function calls(): array
    {
        return self::state()['calls'];
    }

    // ---- connector ----------------------------------------------------------

    public function serverVersion(): string
    {
        $outcome = $this->next('version');
        $this->record('version', '');
        $this->throwFor($outcome);

        return self::state()['server_version'];
    }

    public function findByReference(string $ref): array
    {
        $this->requireKey();
        $outcome = $this->next('search');
        $this->record('search', $ref);
        $this->pause();
        $this->throwFor($outcome);

        $ids = [];
        foreach (self::state()['leads'] as $id => $lead) {
            if (stripos($lead['description'], $ref) !== false) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    public function createLead(array $payload): int
    {
        $this->requireKey();
        $outcome = $this->next('create');
        $this->record('create', (string) ($payload['description'] ?? ''));
        $this->pause();

        if ($outcome !== 'timeout_after') {
            $this->throwFor($outcome);
        }

        $id = self::mutate(function (array &$state) use ($payload) {
            $id = $state['next_id']++;
            $state['leads'][$id] = ['description' => (string) ($payload['description'] ?? ''), 'payload' => $payload];

            return $id;
        });

        if ($outcome === 'timeout_after') {
            throw new ErpException(ErpException::TIMEOUT, 'Timed out waiting for the ERP (mock, after create).');
        }

        return $id;
    }

    public function resolveCountry(string $countryCode): ?int
    {
        return $countryCode === 'EG' ? 65 : null;
    }

    public function resolveRegion(string $countryCode, string $erpCode): ?int
    {
        return $countryCode === 'EG' && $erpCode !== 'ZZ' ? 1000 + abs(crc32($erpCode)) % 1000 : null;
    }

    public function testConnection(string $expectedMajorVersion): ConnectionReport
    {
        $outcome = $this->next('test');
        $version = self::state()['server_version'];

        if ((string) (int) $version !== $expectedMajorVersion) {
            return ConnectionReport::failed(ConnectionReport::STEP_VERSION, "Server runs Odoo {$version}, version selector says {$expectedMajorVersion}.", $version);
        }
        if ($this->connection->apiKey() === null || $outcome === 'auth') {
            return ConnectionReport::failed(ConnectionReport::STEP_AUTHENTICATE, 'The ERP refused the login or API key.', $version);
        }
        if ($outcome === 'fields') {
            return ConnectionReport::failed(ConnectionReport::STEP_FIELDS, 'Lead field missing: function.', $version);
        }
        if ($outcome === 'rights') {
            return ConnectionReport::failed(ConnectionReport::STEP_RIGHTS, 'The user cannot create leads or lacks the All Documents sales role.', $version);
        }

        return ConnectionReport::passed($version, 7);
    }

    // ---- internals ----------------------------------------------------------

    private function requireKey(): void
    {
        if ($this->connection->apiKey() === null) {
            throw new ErpException(ErpException::AUTH, 'No readable API key.');
        }
    }

    private function next(string $operation): string
    {
        return self::mutate(function (array &$state) use ($operation) {
            return array_shift($state['scripts'][$operation]) ?? 'ok';
        });
    }

    private function record(string $operation, string $detail): void
    {
        self::mutate(function (array &$state) use ($operation, $detail) {
            $state['calls'][] = [$operation, $detail];
        });
    }

    private function pause(): void
    {
        $ms = self::state()['delay_ms'];
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }

    private function throwFor(string $outcome): void
    {
        if ($outcome === 'ok') {
            return;
        }
        $class = $outcome === 'timeout_after' ? ErpException::TIMEOUT : $outcome;

        throw new ErpException($class, "Mock ERP: {$class}.");
    }

    /**
     * @return array{leads: array<int, array{description: string, payload: array<string, mixed>}>, next_id: int, scripts: array<string, list<string>>, server_version: string, delay_ms: int, calls: list<array{0: string, 1: string}>}
     */
    private static function state(): array
    {
        return Cache::get(self::STATE_KEY) ?? self::initialState();
    }

    private static function initialState(): array
    {
        return [
            'leads' => [], 'next_id' => 1000, 'server_version' => '18.0', 'delay_ms' => 0, 'calls' => [],
            'scripts' => ['version' => [], 'search' => [], 'create' => [], 'test' => []],
        ];
    }

    /**
     * Read-modify-write under the cache lock, safe across processes.
     */
    private static function mutate(callable $change): mixed
    {
        return Cache::lock(self::LOCK_KEY, 10)->block(10, function () use ($change) {
            $state = self::state();
            $result = $change($state);
            Cache::forever(self::STATE_KEY, $state);

            return $result;
        });
    }
}
