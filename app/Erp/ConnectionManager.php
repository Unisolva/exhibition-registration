<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Models\ErpConnection;
use App\Models\ErpPushItem;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Odoo connection rules (work order §4 erp_connections, §7 "Odoo connections").
 *
 * - The key is write-only; changing URL or database clears it in the same save.
 * - Changing URL or database of a row with sync history archives the row and
 *   starts a new one, so history never claims a visitor reached another server.
 * - The radio button, and URL/database of the active block, cannot change while
 *   the active connection has unfinished items.
 * - A block can be selected, and pushing switched on, only after a passing test
 *   since its last change.
 */
class ConnectionManager
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectorFactory $factory,
    ) {}

    /**
     * @param  array{base_url: string, database: string, login: string, api_key?: string|null}  $input
     */
    public function saveBlock(string $kind, array $input, User $user): ErpConnection
    {
        return DB::transaction(function () use ($kind, $input, $user) {
            $current = ErpConnection::query()->where('kind', $kind)->whereNull('archived_at')->lockForUpdate()->first();
            $url = self::normaliseUrl($input['base_url']);
            $newKey = ($input['api_key'] ?? null) ?: null;

            if ($current === null) {
                $connection = new ErpConnection(['kind' => $kind, 'base_url' => $url, 'database' => $input['database'], 'login' => $input['login']]);
                $connection->api_key = $newKey;
                $connection->changed_at = now();
                $connection->save();
                Audit::record('connection_saved', $connection, meta: ['kind' => $kind, 'created' => true, 'key_set' => $newKey !== null], userId: $user->id);

                return $connection;
            }

            $targetChanged = $current->base_url !== $url || $current->database !== $input['database'];
            if ($targetChanged && $current->is_active && $current->unfinishedItemCount() > 0) {
                throw ValidationException::withMessages(['base_url' => 'The active connection still has unfinished items. Cancel or resolve them first.']);
            }

            $connection = $current;
            if ($targetChanged && $current->hasHistory()) {
                // Keep the history on the old row; the new row starts without a key.
                $current->forceFill(['archived_at' => now(), 'is_active' => false])->save();
                $connection = new ErpConnection(['kind' => $kind]);
                $connection->is_active = $current->is_active;
            }

            $changed = [];
            foreach (['base_url' => $url, 'database' => $input['database'], 'login' => $input['login']] as $field => $value) {
                if ($connection->{$field} !== $value) {
                    $connection->{$field} = $value;
                    $changed[] = $field;
                }
            }
            if ($targetChanged) {
                // A key is never sent to a server it was not entered for.
                $connection->api_key = null;
            }
            if ($newKey !== null) {
                $connection->api_key = $newKey;
                $changed[] = 'api_key';
            }

            if ($changed !== [] || ! $connection->exists) {
                $connection->changed_at = now();
                $connection->save();
                Audit::record('connection_saved', $connection, meta: [
                    'kind' => $kind,
                    'fields' => array_values(array_diff($changed, ['api_key'])),
                    'key_replaced' => in_array('api_key', $changed, true),
                    'archived_previous' => $connection->id !== $current->id ? $current->id : null,
                ], userId: $user->id);

                if ($connection->is_active && $this->settings->get('erp_push_enabled')) {
                    $this->setPushEnabled(false, $user, 'manual');
                }
            }

            return $connection;
        });
    }

    public function test(ErpConnection $connection, User $user): ConnectionReport
    {
        $report = $this->factory->for($connection)->testConnection((string) $this->settings->get('odoo_version'));

        $connection->forceFill([
            'last_test_at' => now(),
            'last_test_ok' => $report->ok,
            'last_test_server_version' => $report->serverVersion ? mb_substr($report->serverVersion, 0, 40) : null,
            'last_test_uid' => $report->uid,
        ])->save();

        Audit::record('connection_tested', $connection, $report->ok ? 'ok' : 'failed', [
            'kind' => $connection->kind, 'failed_step' => $report->failedStep, 'server_version' => $connection->last_test_server_version,
        ], $user->id);

        return $report;
    }

    /** The radio button (§7). */
    public function activate(string $kind, User $user): void
    {
        DB::transaction(function () use ($kind, $user) {
            $rows = ErpConnection::query()->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();
            $target = $rows->firstWhere('kind', $kind);
            $active = $rows->firstWhere('is_active', true);

            if ($target === null) {
                throw ValidationException::withMessages(['kind' => 'Save this connection first.']);
            }
            if ($active?->id === $target->id) {
                return;
            }
            if ($active !== null && $active->unfinishedItemCount() > 0) {
                throw ValidationException::withMessages(['kind' => 'The active connection still has unfinished items. Cancel or resolve them first.']);
            }
            if (! $target->isTested()) {
                throw ValidationException::withMessages(['kind' => 'Run a passing connection test on this block first.']);
            }

            ErpConnection::query()->whereKeyNot($target->id)->update(['is_active' => false]);
            $target->forceFill(['is_active' => true])->save();
            Audit::record('connection_activated', $target, meta: ['kind' => $kind], userId: $user->id);
        });
    }

    /**
     * Push switch. Switching on needs ERP mode odoo and an active block whose
     * test passed since its last change (and since the version selector changed).
     */
    public function setPushEnabled(bool $on, User $user, string $reason = 'manual'): void
    {
        if ($on) {
            $active = ErpConnection::active();
            if ($this->settings->get('erp_mode') !== 'odoo' || $active === null || ! $active->isTested()) {
                throw ValidationException::withMessages(['push' => 'Sending can be switched on only after the active connection passed a test since its last change.']);
            }
        }

        if ((bool) $this->settings->get('erp_push_enabled') === $on) {
            return;
        }
        $this->settings->set('erp_push_enabled', $on, $user->id);
        Audit::record($on ? 'push_enabled' : 'push_disabled', meta: $on ? [] : ['reason' => $reason], userId: $user->id);
    }

    /** Changing the version selector marks both blocks untested and stops pushing. */
    public function setVersion(string $version, User $user): void
    {
        if ($this->settings->get('odoo_version') === $version) {
            return;
        }
        $this->settings->set('odoo_version', $version, $user->id);
        ErpConnection::query()->whereNull('archived_at')->update(['last_test_ok' => null, 'changed_at' => now()]);
        Audit::record('odoo_version_changed', meta: ['version' => $version], userId: $user->id);
        $this->setPushEnabled(false, $user, 'manual');
    }

    /** §16.8: ERP mode none is refused while any item is unfinished. */
    public function setMode(string $mode, User $user): void
    {
        if ($mode === $this->settings->get('erp_mode')) {
            return;
        }
        if ($mode === 'none') {
            if (DB::table('erp_push_items')->whereIn('state', ErpPushItem::UNFINISHED)->exists()) {
                throw ValidationException::withMessages(['erp_mode' => 'Some items are still unfinished. Cancel or resolve them first.']);
            }
            $this->setPushEnabled(false, $user, 'manual');
            ErpConnection::query()->update(['is_active' => false]);
        }
        $this->settings->set('erp_mode', $mode, $user->id);
        Audit::record('settings_updated', meta: ['keys' => ['erp_mode']], userId: $user->id);
    }

    /** https only, host (and port) only, no path. */
    public static function normaliseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
            throw ValidationException::withMessages(['base_url' => 'Enter the Odoo address as https://host, without a path.']);
        }

        return 'https://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
