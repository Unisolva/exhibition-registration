<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\ConnectionReport;
use App\Erp\ConnectorFactory;
use App\Erp\ErpException;
use App\Erp\Odoo\Odoo18JsonRpcConnector;
use App\Models\ErpConnection;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Odoo 18 JSON-RPC connector against faked HTTP: request shape, error
 * classes (§8 step 6), uid caching and the four connection-test steps. The
 * same connector runs against the real UAT server in OdooUatTest.
 */
class OdooConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://odoo.example.test';

    private function connector(): Odoo18JsonRpcConnector
    {
        $connection = new ErpConnection(['kind' => 'test', 'base_url' => self::BASE, 'database' => 'sample-db', 'login' => 'crm-api@example.test']);
        $connection->api_key = 'fake-api-key-1234';
        $connection->save();

        return new Odoo18JsonRpcConnector($connection);
    }

    private static function ok(mixed $result): PromiseInterface
    {
        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);
    }

    private static function rpcError(string $name, string $message = 'secret detail: Visitor Name 01001234567'): PromiseInterface
    {
        return Http::response(['jsonrpc' => '2.0', 'id' => 1, 'error' => [
            'code' => 200, 'message' => 'Odoo Server Error', 'data' => ['name' => $name, 'message' => $message, 'debug' => 'Traceback ... '.$message],
        ]]);
    }

    /**
     * The request body as the server receives it (JSON objects as arrays).
     *
     * @return array<string, mixed>
     */
    private static function params(Request $request): array
    {
        return json_decode((string) json_encode($request->data()), true)['params'] ?? [];
    }

    /**
     * @return array<int, mixed>
     */
    private static function args(Request $request): array
    {
        return self::params($request)['args'] ?? [];
    }

    /**
     * A fresh fake per scenario, routed by JSON-RPC method (and model.method for execute_kw).
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeRpc(array $routes): void
    {
        Http::swap(new HttpFactory);
        Http::fake(function (Request $request) use ($routes) {
            if (str_ends_with($request->url(), '/web/webclient/version_info')) {
                return $routes['version'] ?? self::ok(['server_version' => '18.0-20251121', 'server_version_info' => [18, 0, 0, 'final', 0, '']]);
            }
            $params = self::params($request);
            if ($params['method'] === 'authenticate') {
                return $routes['authenticate'] ?? self::ok(42);
            }

            return $routes[$params['args'][3].'.'.$params['args'][4]] ?? self::ok(null);
        });
    }

    public function test_requests_carry_the_app_user_agent_and_json_rpc_shape(): void
    {
        $this->fakeRpc(['crm.lead.search' => self::ok([7, 9])]);

        $this->assertSame([7, 9], $this->connector()->findByReference('EXR-01JTESTREF0000000000000001'));

        Http::assertSent(function (Request $request) {
            $params = self::params($request);
            if (! str_ends_with($request->url(), '/jsonrpc') || ($params['method'] ?? '') !== 'execute_kw') {
                return false;
            }
            $args = $params['args'];

            return $request->header('User-Agent') === ['ExhibitionRegistration/'.config('exhibition.version')]
                && $params['service'] === 'object'
                && $args[0] === 'sample-db' && $args[1] === 42 && $args[2] === 'fake-api-key-1234'
                && $args[3] === 'crm.lead' && $args[4] === 'search'
                && $args[5] === [[['description', 'ilike', 'EXR-01JTESTREF0000000000000001']]]
                && $args[6] === ['context' => ['active_test' => false]];
        });
    }

    public function test_the_uid_is_cached_for_the_life_of_the_connector(): void
    {
        $this->fakeRpc(['crm.lead.search' => self::ok([]), 'res.country.search' => self::ok([65])]);
        $connector = $this->connector();

        $connector->findByReference('EXR-A');
        $connector->findByReference('EXR-B');
        $this->assertSame(65, $connector->resolveCountry('eg'));

        Http::assertSentCount(4);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => (self::params($pair[0])['method'] ?? '') === 'authenticate'));
    }

    public function test_create_converts_tags_to_a_replace_command_and_returns_the_id(): void
    {
        $this->fakeRpc(['crm.lead.create' => self::ok(501)]);

        $id = $this->connector()->createLead(['name' => 'Lead', 'type' => 'lead', 'user_id' => false, 'tag_ids' => [4, 9]]);

        $this->assertSame(501, $id);
        Http::assertSent(fn (Request $r) => (self::args($r)[4] ?? null) === 'create'
            && self::args($r)[5] === [['name' => 'Lead', 'type' => 'lead', 'user_id' => false, 'tag_ids' => [[6, 0, [4, 9]]]]]);
    }

    public function test_regions_are_resolved_by_country_code_and_state_code(): void
    {
        $this->fakeRpc(['res.country.state.search' => self::ok([1234])]);

        $this->assertSame(1234, $this->connector()->resolveRegion('EG', 'ALX'));
        Http::assertSent(fn (Request $r) => (self::args($r)[3] ?? null) === 'res.country.state'
            && self::args($r)[5] === [[['country_id.code', '=', 'EG'], ['code', '=', 'ALX']]]
            && self::args($r)[6] === ['limit' => 1]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rpcErrors(): array
    {
        return [
            'access denied' => ['odoo.exceptions.AccessDenied', ErpException::AUTH],
            'access error' => ['odoo.exceptions.AccessError', ErpException::AUTH],
            'validation error' => ['odoo.exceptions.ValidationError', ErpException::VALIDATION],
            'user error' => ['odoo.exceptions.UserError', ErpException::VALIDATION],
            'value error' => ['builtins.ValueError', ErpException::VALIDATION],
            'database constraint' => ['psycopg2.errors.NotNullViolation', ErpException::VALIDATION],
            'anything else' => ['odoo.exceptions.CacheMiss', ErpException::UNKNOWN],
        ];
    }

    #[DataProvider('rpcErrors')]
    public function test_odoo_errors_map_to_classes_without_their_text(string $name, string $class): void
    {
        $this->fakeRpc(['crm.lead.create' => self::rpcError($name)]);

        try {
            $this->connector()->createLead(['name' => 'Lead']);
            $this->fail('Expected an ErpException.');
        } catch (ErpException $e) {
            $this->assertSame($class, $e->errorClass);
            $this->assertSame('ERP error: '.$name, $e->getMessage());
            $this->assertStringNotContainsString('Visitor Name', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{\Closure, string}>
     */
    public static function transportFailures(): array
    {
        return [
            '429' => [fn () => Http::response('slow down', 429), ErpException::RATE_LIMITED],
            '502' => [fn () => Http::response('bad gateway', 502), ErpException::HTTP_5XX],
            '503' => [fn () => Http::response('', 503), ErpException::HTTP_5XX],
            'firewall 403' => [fn () => Http::response('error code: 1010', 403), ErpException::UNKNOWN],
            'not json' => [fn () => Http::response('<html>', 200), ErpException::UNKNOWN],
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds'), ErpException::TIMEOUT],
            'refused' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect'), ErpException::NETWORK],
        ];
    }

    #[DataProvider('transportFailures')]
    public function test_transport_failures_map_to_classes(\Closure $response, string $class): void
    {
        Http::swap(new HttpFactory);
        Http::fake(fn () => $response());

        try {
            $this->connector()->serverVersion();
            $this->fail('Expected an ErpException.');
        } catch (ErpException $e) {
            $this->assertSame($class, $e->errorClass);
        }
    }

    public function test_a_refused_login_is_an_auth_error(): void
    {
        $this->fakeRpc(['authenticate' => self::ok(false)]);

        $this->expectExceptionObject(new ErpException(ErpException::AUTH, 'The ERP refused the login or API key.'));
        $this->connector()->findByReference('EXR-X');
    }

    public function test_the_connection_test_passes_all_four_steps(): void
    {
        $this->fakeRpc([
            'crm.lead.fields_get' => self::ok(array_fill_keys(Odoo18JsonRpcConnector::LEAD_FIELDS, ['type' => 'char'])),
            'crm.lead.has_access' => self::ok(true),
            'res.users.has_group' => self::ok(true),
        ]);

        $report = $this->connector()->testConnection('18');

        $this->assertTrue($report->ok, $report->message);
        $this->assertSame(42, $report->uid);
        $this->assertSame('18.0-20251121', $report->serverVersion);
        Http::assertSent(fn (Request $r) => (self::args($r)[4] ?? null) === 'has_group'
            && self::args($r)[5] === [[42], Odoo18JsonRpcConnector::ALL_DOCUMENTS_GROUP]);
        Http::assertSent(fn (Request $r) => (self::args($r)[4] ?? null) === 'has_access' && self::args($r)[5] === [[], 'create']);
    }

    public function test_the_connection_test_reports_the_first_failing_step(): void
    {
        $this->fakeRpc(['version' => self::ok(['server_version' => '17.0-20240101'])]);
        $report = $this->connector()->testConnection('18');
        $this->assertSame([ConnectionReport::STEP_VERSION, 'Server runs Odoo 17.0-20240101, version selector says 18.'], [$report->failedStep, $report->message]);

        $this->fakeRpc(['authenticate' => self::ok(false)]);
        $this->assertSame(ConnectionReport::STEP_AUTHENTICATE, $this->connector()->testConnection('18')->failedStep);

        $fields = array_fill_keys(Odoo18JsonRpcConnector::LEAD_FIELDS, ['type' => 'char']);
        unset($fields['function']);
        $this->fakeRpc(['crm.lead.fields_get' => self::ok($fields)]);
        $report = $this->connector()->testConnection('18');
        $this->assertSame([ConnectionReport::STEP_FIELDS, 'Lead fields missing or not readable: function.'], [$report->failedStep, $report->message]);

        $this->fakeRpc([
            'crm.lead.fields_get' => self::ok(array_fill_keys(Odoo18JsonRpcConnector::LEAD_FIELDS, ['type' => 'char'])),
            'crm.lead.has_access' => self::ok(true),
            'res.users.has_group' => self::ok(false),
        ]);
        $report = $this->connector()->testConnection('18');
        $this->assertSame([ConnectionReport::STEP_RIGHTS, 'The user lacks the Sales role "User: All Documents".'], [$report->failedStep, $report->message]);
    }

    public function test_the_registry_picks_this_connector_when_the_mock_is_off(): void
    {
        config(['exhibition.erp_mock' => false]);
        $connection = new ErpConnection(['kind' => 'live', 'base_url' => self::BASE, 'database' => 'x', 'login' => 'y']);

        $this->assertInstanceOf(Odoo18JsonRpcConnector::class, app(ConnectorFactory::class)->for($connection));
    }
}
