<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp\Odoo;

use App\Erp\ConnectionReport;
use App\Erp\ErpException;
use App\Erp\ErpLeadConnector;
use App\Models\ErpConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Odoo 18 over its external JSON-RPC API (work order §8): POST {base}/jsonrpc
 * with common.authenticate, then object.execute_kw. The uid is cached for the
 * life of the instance, which is one drain run or one connection test.
 *
 * The HTTP client never retries by itself (§8 step 8); timeouts are 5 s to
 * connect and 15 s in total, TLS verification on. Error messages carry the
 * Odoo exception name only, never its text (which can echo lead values).
 */
class Odoo18JsonRpcConnector implements ErpLeadConnector
{
    /** Lead fields the payload can use; the connection test checks each exists. */
    public const LEAD_FIELDS = [
        'name', 'type', 'user_id', 'contact_name', 'phone', 'email_from', 'function',
        'country_id', 'state_id', 'campaign_id', 'source_id', 'tag_ids', 'description',
    ];

    /** Sales role that sees every lead, needed so the reference search sees assigned leads (§14). */
    public const ALL_DOCUMENTS_GROUP = 'sales_team.group_sale_salesman_all_leads';

    private ?int $uid = null;

    private int $requestId = 0;

    public function __construct(private readonly ErpConnection $connection) {}

    public function serverVersion(): string
    {
        $result = $this->post('/web/webclient/version_info', ['jsonrpc' => '2.0', 'method' => 'call', 'params' => (object) []]);
        $version = is_array($result) ? ($result['server_version'] ?? null) : null;
        if (! is_string($version) || $version === '') {
            throw new ErpException(ErpException::UNKNOWN, 'The server did not report a version.');
        }

        return $version;
    }

    /**
     * Decision D4 option (b): the "Ref:" line in the description, searched with
     * ilike, archived leads included. Swapping to an indexed x_unisolva_ref
     * field with "=" changes only this method.
     */
    public function findByReference(string $ref): array
    {
        $ids = $this->execute('crm.lead', 'search', [[['description', 'ilike', $ref]]], ['context' => ['active_test' => false]]);

        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    public function createLead(array $payload): int
    {
        if (isset($payload['tag_ids']) && is_array($payload['tag_ids'])) {
            // Many2many write command: replace with exactly these tags.
            $payload['tag_ids'] = [[6, 0, array_values(array_map('intval', $payload['tag_ids']))]];
        }

        $id = $this->execute('crm.lead', 'create', [$payload]);
        if (is_array($id)) {
            $id = $id[0] ?? null;
        }
        if (! is_int($id) || $id <= 0) {
            throw new ErpException(ErpException::UNKNOWN, 'The ERP did not return a lead ID.');
        }

        return $id;
    }

    public function resolveCountry(string $countryCode): ?int
    {
        $ids = $this->execute('res.country', 'search', [[['code', '=', strtoupper($countryCode)]]], ['limit' => 1]);

        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : null;
    }

    public function resolveRegion(string $countryCode, string $erpCode): ?int
    {
        $ids = $this->execute('res.country.state', 'search', [[
            ['country_id.code', '=', strtoupper($countryCode)],
            ['code', '=', $erpCode],
        ]], ['limit' => 1]);

        return is_array($ids) && isset($ids[0]) ? (int) $ids[0] : null;
    }

    /**
     * Work order §7 connection test: (1) version without login, (2) login,
     * (3) the mapped lead fields exist, (4) the user can read and create leads
     * and has the All Documents sales role. Reports the first failing step.
     */
    public function testConnection(string $expectedMajorVersion): ConnectionReport
    {
        try {
            $version = $this->serverVersion();
        } catch (ErpException $e) {
            return ConnectionReport::failed(ConnectionReport::STEP_VERSION, 'Could not read the server version: '.$e->getMessage());
        }
        if ((string) (int) $version !== $expectedMajorVersion) {
            return ConnectionReport::failed(ConnectionReport::STEP_VERSION, "Server runs Odoo {$version}, version selector says {$expectedMajorVersion}.", $version);
        }

        try {
            $uid = $this->uid();
        } catch (ErpException $e) {
            return ConnectionReport::failed(ConnectionReport::STEP_AUTHENTICATE, 'Login failed: '.$e->getMessage(), $version);
        }

        try {
            $fields = $this->execute('crm.lead', 'fields_get', [self::LEAD_FIELDS], ['attributes' => ['type']]);
            $missing = array_values(array_diff(self::LEAD_FIELDS, array_keys(is_array($fields) ? $fields : [])));
            if ($missing !== []) {
                return ConnectionReport::failed(ConnectionReport::STEP_FIELDS, 'Lead fields missing or not readable: '.implode(', ', $missing).'.', $version);
            }
        } catch (ErpException $e) {
            return ConnectionReport::failed(ConnectionReport::STEP_FIELDS, 'Could not read the lead fields: '.$e->getMessage(), $version);
        }

        try {
            // Record methods take the record IDs as the first argument; an empty set checks the model.
            $canRead = $this->execute('crm.lead', 'has_access', [[], 'read']);
            $canCreate = $this->execute('crm.lead', 'has_access', [[], 'create']);
            $allDocuments = $this->execute('res.users', 'has_group', [[$uid], self::ALL_DOCUMENTS_GROUP]);
        } catch (ErpException $e) {
            return ConnectionReport::failed(ConnectionReport::STEP_RIGHTS, 'Could not check the user\'s rights: '.$e->getMessage(), $version);
        }
        if ($canRead !== true || $canCreate !== true) {
            return ConnectionReport::failed(ConnectionReport::STEP_RIGHTS, 'The user cannot read and create leads.', $version);
        }
        if ($allDocuments !== true) {
            return ConnectionReport::failed(ConnectionReport::STEP_RIGHTS, 'The user lacks the Sales role "User: All Documents".', $version);
        }

        return ConnectionReport::passed($version, $uid);
    }

    // ---- JSON-RPC -------------------------------------------------------------

    /**
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    private function execute(string $model, string $method, array $args, array $kwargs = []): mixed
    {
        return $this->call('object', 'execute_kw', [
            $this->connection->database, $this->uid(), $this->key(), $model, $method, $args, (object) $kwargs,
        ]);
    }

    private function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $uid = $this->call('common', 'authenticate', [$this->connection->database, $this->connection->login, $this->key(), (object) []]);
        if (! is_int($uid) || $uid <= 0) {
            throw new ErpException(ErpException::AUTH, 'The ERP refused the login or API key.');
        }

        return $this->uid = $uid;
    }

    private function key(): string
    {
        return $this->connection->apiKey() ?? throw new ErpException(ErpException::AUTH, 'No readable API key; it must be re-entered.');
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function call(string $service, string $method, array $args): mixed
    {
        return $this->post('/jsonrpc', [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
            'id' => ++$this->requestId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): mixed
    {
        try {
            $response = $this->http()->post(rtrim($this->connection->base_url, '/').$path, $body);
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;

            throw new ErpException($timedOut ? ErpException::TIMEOUT : ErpException::NETWORK, $timedOut ? 'The ERP did not answer in time.' : 'Could not connect to the ERP.');
        }

        $status = $response->status();
        if ($status === 429) {
            throw new ErpException(ErpException::RATE_LIMITED, 'The ERP asked to slow down (HTTP 429).');
        }
        if ($status >= 500) {
            throw new ErpException(ErpException::HTTP_5XX, "The ERP answered HTTP {$status}.");
        }
        if ($status !== 200) {
            // A 403 here usually comes from a firewall in front of Odoo, not from Odoo.
            throw new ErpException(ErpException::UNKNOWN, "The ERP answered HTTP {$status}.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ErpException(ErpException::UNKNOWN, 'The ERP answer was not JSON.');
        }
        if (isset($json['error'])) {
            throw self::fromRpcError($json['error']);
        }

        return $json['result'] ?? null;
    }

    private function http(): PendingRequest
    {
        return Http::withUserAgent('ExhibitionRegistration/'.config('app.version'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->withOptions(['verify' => true]);
    }

    /**
     * Error class from the Odoo exception name (§8 step 6). Only the name is
     * kept: the message text can contain values from the payload.
     *
     * @param  array<string, mixed>|mixed  $error
     */
    public static function fromRpcError(mixed $error): ErpException
    {
        $name = is_array($error) && is_array($error['data'] ?? null) ? (string) ($error['data']['name'] ?? '') : '';
        $name = mb_substr(preg_replace('/[^A-Za-z0-9_.]/', '', $name) ?? '', 0, 120);

        $class = match (true) {
            in_array($name, ['odoo.exceptions.AccessDenied', 'odoo.exceptions.AccessError', 'odoo.http.SessionExpiredException'], true) => ErpException::AUTH,
            in_array($name, ['odoo.exceptions.ValidationError', 'odoo.exceptions.UserError', 'odoo.exceptions.MissingError',
                'builtins.ValueError', 'builtins.TypeError', 'builtins.KeyError'], true),
            str_starts_with($name, 'psycopg2.') => ErpException::VALIDATION,
            default => ErpException::UNKNOWN,
        };

        return new ErpException($class, 'ERP error: '.($name !== '' ? $name : 'unnamed'));
    }
}
