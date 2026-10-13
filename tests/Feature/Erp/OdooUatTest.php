<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Erp;

use App\Erp\ConnectorFactory;
use App\Erp\Drain;
use App\Erp\Odoo\Odoo18JsonRpcConnector;
use App\Erp\PushService;
use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The real Odoo 18 connector against a UAT server (verifications V9, V10 and
 * V11 on UAT, and one push end to end). Skipped unless the environment names a
 * UAT server: CI never sets these, so CI stays mock-only.
 *
 *   ODOO_UAT_URL, ODOO_UAT_DB              the UAT server and database
 *   ODOO_UAT_LOGIN, ODOO_UAT_KEY           the CRM-only API user (what the app uses)
 *   ODOO_UAT_ADMIN_LOGIN, ODOO_UAT_ADMIN_KEY   an administrator, for merging and cleanup only
 *
 * Every lead it creates carries an invented visitor and a marker, and is
 * deleted at the end (by the administrator: the CRM-only user has no delete
 * right, as in production).
 */
class OdooUatTest extends TestCase
{
    use RefreshDatabase;

    private const ENV = ['ODOO_UAT_URL', 'ODOO_UAT_DB', 'ODOO_UAT_LOGIN', 'ODOO_UAT_KEY', 'ODOO_UAT_ADMIN_LOGIN', 'ODOO_UAT_ADMIN_KEY'];

    /** @var list<int> lead IDs to delete at the end */
    private array $created = [];

    private ?int $adminUid = null;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV as $name) {
            if ((string) getenv($name) === '') {
                $this->markTestSkipped('UAT Odoo not configured ('.$name.' unset).');
            }
        }
        config(['exhibition.erp_mock' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->created !== []) {
            // A merge deletes one of the merged leads, so delete only those that still exist.
            $ids = array_values(array_unique($this->created));
            $existing = $this->admin('crm.lead', 'search', [[['id', 'in', $ids]]], ['context' => ['active_test' => false]]);
            if ($existing !== []) {
                $this->admin('crm.lead', 'unlink', [$existing]);
            }
            $left = $this->admin('crm.lead', 'search', [[['id', 'in', $ids]]], ['context' => ['active_test' => false]]);
            $this->assertSame([], $left, 'UAT cleanup left leads behind.');
        }
        parent::tearDown();
    }

    private function connection(): ErpConnection
    {
        $connection = new ErpConnection([
            'kind' => 'test', 'base_url' => rtrim((string) getenv('ODOO_UAT_URL'), '/'),
            'database' => (string) getenv('ODOO_UAT_DB'), 'login' => (string) getenv('ODOO_UAT_LOGIN'),
        ]);
        $connection->api_key = (string) getenv('ODOO_UAT_KEY');
        $connection->save();

        return $connection;
    }

    private function connector(): Odoo18JsonRpcConnector
    {
        return new Odoo18JsonRpcConnector($this->connection());
    }

    /** An administrator call over JSON-RPC, for merge and cleanup only. */
    private function admin(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        $url = rtrim((string) getenv('ODOO_UAT_URL'), '/').'/jsonrpc';
        $call = function (string $service, string $method, array $args) use ($url) {
            $response = Http::withUserAgent('ExhibitionRegistration/'.config('app.version'))->timeout(30)
                ->post($url, ['jsonrpc' => '2.0', 'method' => 'call', 'params' => compact('service', 'method', 'args'), 'id' => 1])->json();
            $this->assertArrayNotHasKey('error', $response, 'Admin call failed: '.($response['error']['data']['name'] ?? '?'));

            return $response['result'];
        };

        $this->adminUid ??= $call('common', 'authenticate', [getenv('ODOO_UAT_DB'), getenv('ODOO_UAT_ADMIN_LOGIN'), getenv('ODOO_UAT_ADMIN_KEY'), (object) []]);

        return $call('object', 'execute_kw', [getenv('ODOO_UAT_DB'), $this->adminUid, getenv('ODOO_UAT_ADMIN_KEY'), $model, $method, $args, (object) $kwargs]);
    }

    private function marker(): string
    {
        return 'EXR-'.Str::ulid();
    }

    private function createMarkedLead(Odoo18JsonRpcConnector $connector, string $marker, string $name = 'UAT Test Visitor'): int
    {
        $id = $connector->createLead([
            'name' => "UAT test lead: {$name}", 'type' => 'lead', 'user_id' => false, 'contact_name' => $name,
            'phone' => '+201000000000', 'description' => '<p>Ref: '.$marker.'<br>Automated test lead, deleted after the test.</p>',
        ]);
        $this->created[] = $id;

        return $id;
    }

    public function test_v9_the_server_runs_odoo_18(): void
    {
        $version = $this->connector()->serverVersion();

        $this->assertSame(18, (int) $version, "Server version {$version}");
    }

    public function test_the_connection_test_passes_all_four_steps_for_the_crm_user(): void
    {
        $report = $this->connector()->testConnection('18');

        $this->assertTrue($report->ok, $report->failedStep.': '.$report->message);
        $this->assertGreaterThan(0, $report->uid);
    }

    public function test_v10_on_uat_every_mapped_lead_field_exists_with_the_expected_type(): void
    {
        $fields = $this->admin('crm.lead', 'fields_get', [Odoo18JsonRpcConnector::LEAD_FIELDS], ['attributes' => ['string', 'type', 'required']]);

        $expected = [
            'name' => 'char', 'type' => 'selection', 'user_id' => 'many2one', 'contact_name' => 'char', 'phone' => 'char',
            'email_from' => 'char', 'function' => 'char', 'country_id' => 'many2one', 'state_id' => 'many2one',
            'campaign_id' => 'many2one', 'source_id' => 'many2one', 'tag_ids' => 'many2many', 'description' => 'html',
        ];
        foreach ($expected as $field => $type) {
            $this->assertArrayHasKey($field, $fields, "crm.lead.{$field} is missing");
            $this->assertSame($type, $fields[$field]['type'], "crm.lead.{$field} type");
        }
    }

    public function test_v11_the_reference_finds_exactly_one_lead_when_created_archived_assigned_and_merged(): void
    {
        $connector = $this->connector();

        // Created.
        $marker = $this->marker();
        $id = $this->createMarkedLead($connector, $marker);
        $this->assertSame([$id], $connector->findByReference($marker));

        // Archived.
        $this->admin('crm.lead', 'write', [[$id], ['active' => false]]);
        $this->assertSame([$id], $this->connector()->findByReference($marker));

        // Assigned to someone else (the CRM-only user must still see it: All Documents).
        $this->admin('crm.lead', 'write', [[$id], ['active' => true, 'user_id' => $this->adminUid]]);
        $this->assertSame([$id], $this->connector()->findByReference($marker));

        // Merged with a second lead: each marker still finds exactly one lead.
        $otherMarker = $this->marker();
        $other = $this->createMarkedLead($connector, $otherMarker, 'UAT Test Visitor Two');
        $wizard = $this->admin('crm.merge.opportunity', 'create', [['opportunity_ids' => [[6, 0, [$id, $other]]]]]);
        $this->admin('crm.merge.opportunity', 'action_merge', [[$wizard]]);
        $survivor = $this->connector()->findByReference($marker);
        $this->assertCount(1, $survivor);
        $this->assertSame($survivor, $this->connector()->findByReference($otherMarker));
        $this->created[] = $survivor[0];
    }

    public function test_one_push_end_to_end_creates_an_unassigned_lead_and_a_second_push_creates_nothing(): void
    {
        $settings = app(Settings::class);
        $settings->set('erp_mode', 'odoo');
        $settings->set('erp_push_enabled', true);
        $connection = $this->connection();
        $connection->forceFill(['is_active' => true])->save();
        $visitor = Visitor::factory()->create(['name' => 'UAT Push Visitor', 'region_code' => 'C', 'region_label' => 'Cairo']);
        $admin = User::factory()->admin()->create();
        $this->assertInstanceOf(Odoo18JsonRpcConnector::class, app(ConnectorFactory::class)->for($connection));

        app(PushService::class)->createBatch($admin, $connection, [$visitor->id], (string) Str::ulid());
        $result = app(Drain::class)->run(5, 60);
        $item = ErpPushItem::query()->sole();
        if ($item->erp_lead_id) {
            $this->created[] = $item->erp_lead_id;
        }

        $this->assertSame('ok', $result['outcome'], json_encode($result).' item error: '.$item->error_class.' '.$item->error_message);
        $this->assertSame('succeeded', $item->state);
        $lead = $this->admin('crm.lead', 'read', [[$item->erp_lead_id], ['type', 'user_id', 'contact_name', 'description', 'country_id', 'state_id']])[0];
        $this->assertSame('lead', $lead['type']);
        $this->assertFalse($lead['user_id'], 'The lead is unassigned.');
        $this->assertSame('UAT Push Visitor', $lead['contact_name']);
        $this->assertStringContainsString('Ref: EXR-', $lead['description']);
        $this->assertNotFalse($lead['country_id']);
        $this->assertNotFalse($lead['state_id']);

        // A second push of the same visitor creates nothing.
        $again = app(PushService::class)->createBatch($admin, $connection, [$visitor->id], (string) Str::ulid());
        $this->assertNull($again['batch']);
    }
}
