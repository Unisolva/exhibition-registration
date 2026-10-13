<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Support;

use App\Erp\Drain;
use App\Erp\MockConnector;
use App\Erp\PushService;
use App\Models\ErpBatch;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An installation ready to push through the mock ERP, and helpers to queue
 * and drain. Hosts are invented.
 */
trait PushFixtures
{
    protected function readyToPush(string $kind = 'live'): ErpConnection
    {
        MockConnector::reset();
        $settings = app(Settings::class);
        $settings->set('erp_mode', 'odoo');
        $settings->set('erp_push_enabled', true);

        return $this->testedConnection($kind, active: true);
    }

    protected function testedConnection(string $kind, bool $active = false): ErpConnection
    {
        $connection = new ErpConnection([
            'kind' => $kind,
            'base_url' => "https://{$kind}-erp.example.test",
            'database' => "{$kind}-db",
            'login' => 'crm-api@example.test',
        ]);
        $connection->api_key = 'mock-key-'.$kind.'-WXYZ';
        $connection->is_active = $active;
        $connection->changed_at = now()->subHour();
        $connection->last_test_at = now()->subMinutes(30);
        $connection->last_test_ok = true;
        $connection->last_test_server_version = '18.0';
        $connection->last_test_uid = 7;
        $connection->save();

        return $connection;
    }

    /**
     * @param  list<Visitor>  $visitors
     */
    protected function queue(array $visitors, ?User $by = null, ?ErpConnection $connection = null): ErpBatch
    {
        $result = app(PushService::class)->createBatch(
            $by ?? User::factory()->admin()->create(),
            $connection ?? ErpConnection::active(),
            array_map(fn (Visitor $v) => $v->id, $visitors),
            (string) Str::ulid(),
        );

        return $result['batch'];
    }

    /**
     * @return array{outcome: string, items: int, error_class: string|null}
     */
    protected function drain(int $maxItems = 25): array
    {
        app(Settings::class)->forget();

        return app(Drain::class)->run($maxItems, 45);
    }

    protected function item(int $id): ErpPushItem
    {
        return ErpPushItem::query()->findOrFail($id);
    }

    protected function sync(Visitor $visitor, ErpConnection $connection): ?object
    {
        return DB::table('lead_syncs')->where('visitor_id', $visitor->id)->where('connection_id', $connection->id)->first();
    }

    /** Let an item be claimed now (skip its backoff). */
    protected function makeDue(int $itemId): void
    {
        DB::table('erp_push_items')->where('id', $itemId)->update(['next_attempt_at' => now()->subSecond()]);
    }
}
