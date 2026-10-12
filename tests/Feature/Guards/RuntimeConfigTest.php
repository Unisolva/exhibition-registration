<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Work order §2 and §0b: the effective runtime configuration, instead of a scan
 * of the stock config files (which mention Redis in comments and unused options).
 */
class RuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cache_and_queue_use_the_database_and_sync_drivers(): void
    {
        $this->assertSame('database', config('session.driver'));
        $this->assertSame('database', config('cache.stores.'.config('cache.default').'.driver'));
        $this->assertSame('sync', config('queue.connections.'.config('queue.default').'.driver'));
    }

    public function test_config_fallbacks_match_the_hosting_profile_when_env_is_silent(): void
    {
        $fallbacks = [
            'session.php' => "env('SESSION_DRIVER', 'database')",
            'cache.php' => "env('CACHE_STORE', 'database')",
            'queue.php' => "env('QUEUE_CONNECTION', 'sync')",
            'database.php' => "env('DB_CONNECTION', 'mariadb')",
        ];

        foreach ($fallbacks as $file => $expected) {
            $this->assertStringContainsString($expected, file_get_contents(config_path($file)), "config/{$file}");
        }
    }

    public function test_no_redis_connection_is_used_at_runtime(): void
    {
        $this->get('/a-page-that-does-not-exist')->assertNotFound();
        Cache::put('runtime-config-probe', 'ok', 60);
        $this->assertSame('ok', Cache::get('runtime-config-probe'));

        $this->assertFalse(app()->resolved('redis'), 'The Redis manager was resolved during a request.');
    }

    public function test_the_connection_is_mariadb_10_11_with_utf8mb4_set_by_the_app(): void
    {
        $this->assertSame('mariadb', DB::connection()->getDriverName());
        $this->assertSame('utf8mb4', config('database.connections.mariadb.charset'));
        $this->assertSame('utf8mb4_unicode_ci', config('database.connections.mariadb.collation'));

        // The server default on the hosting profile is latin1, so these prove the
        // connection itself asks for utf8mb4.
        $row = DB::selectOne('SELECT @@character_set_connection AS cs, @@collation_connection AS co, VERSION() AS v');
        $this->assertSame('utf8mb4', $row->cs);
        $this->assertSame('utf8mb4_unicode_ci', $row->co);
        $this->assertStringStartsWith('10.11.', $row->v);
    }
}
