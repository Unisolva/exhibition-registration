<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Work order §9: no names, phones or other request data in logs, even when a
 * database error carries them in its message.
 */
class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/redaction-test.log');
        @unlink($this->logFile);
        config([
            'logging.default' => 'redaction',
            'logging.channels.redaction' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
        ]);
        Log::forgetChannel('redaction');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    public function test_a_failed_query_is_logged_without_its_values(): void
    {
        $existing = User::factory()->create(['email' => 'dup@example.test']);

        try {
            DB::table('users')->insert([
                'name' => 'Visitor Invented-Name',
                'email' => $existing->email,
                'password' => 'x',
                'role' => 'staff',
                'remember_token' => '01001234567',
            ]);
            $this->fail('Expected a duplicate key error.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Invented-Name', $e->getMessage(), 'Precondition: the message carries the values.');
            report($e);
        }

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('Database query failed', $log);
        $this->assertStringNotContainsString('Invented-Name', $log);
        $this->assertStringNotContainsString('01001234567', $log);
        $this->assertStringNotContainsString('dup@example.test', $log);
    }

    public function test_an_exception_during_a_request_logs_no_request_body_and_carries_the_request_id(): void
    {
        Route::post('/staff/__boom', function () {
            throw new \RuntimeException('boom');
        });

        $response = $this->post('/staff/__boom', ['name' => 'Visitor Invented-Name', 'phone' => '01001234567']);
        $response->assertStatus(500);

        $log = (string) file_get_contents($this->logFile);
        $this->assertStringContainsString('boom', $log);
        $this->assertStringContainsString($response->headers->get('X-Request-Id'), $log);
        $this->assertStringNotContainsString('Invented-Name', $log);
        $this->assertStringNotContainsString('01001234567', $log);
    }
}
