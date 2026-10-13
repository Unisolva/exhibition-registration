<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An Odoo connection block, Test or Live (work order §4 erp_connections, §7).
 * The API key uses Laravel's encrypted cast and is never sent to a browser.
 *
 * @property int $id
 * @property string $kind test|live
 * @property string $base_url
 * @property string $database
 * @property string $login
 * @property bool $is_active
 * @property Carbon|null $archived_at
 * @property Carbon|null $last_test_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_server_version
 * @property int|null $last_test_uid
 * @property Carbon|null $changed_at
 */
class ErpConnection extends Model
{
    protected $guarded = ['id', 'api_key'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'last_test_at' => 'datetime',
            'last_test_ok' => 'boolean',
            'changed_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->whereNull('archived_at')->first();
    }

    public static function current(string $kind): ?self
    {
        return static::query()->where('kind', $kind)->whereNull('archived_at')->first();
    }

    /** The decrypted key, or null when none is stored or it cannot be read (APP_KEY rotated). */
    public function apiKey(): ?string
    {
        try {
            $key = $this->api_key;
        } catch (DecryptException) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * none, saved or unreadable; with the last four characters when saved.
     *
     * @return array{status: string, ends: string|null}
     */
    public function keyStatus(): array
    {
        if ($this->getRawOriginal('api_key') === null) {
            return ['status' => 'none', 'ends' => null];
        }
        $key = $this->apiKey();

        return $key === null ? ['status' => 'unreadable', 'ends' => null] : ['status' => 'saved', 'ends' => substr($key, -4)];
    }

    /** Its last connection test passed after its last change (work order §7). */
    public function isTested(): bool
    {
        return $this->last_test_ok === true && $this->last_test_at !== null
            && ($this->changed_at === null || $this->last_test_at->greaterThanOrEqualTo($this->changed_at));
    }

    public function hasHistory(): bool
    {
        return DB::table('lead_syncs')->where('connection_id', $this->id)->exists()
            || DB::table('erp_push_items')->where('connection_id', $this->id)->exists();
    }

    /** Items not finished: queued, processing, failed_retry or needs_review. */
    public function unfinishedItemCount(): int
    {
        return DB::table('erp_push_items')->where('connection_id', $this->id)
            ->whereIn('state', ErpPushItem::UNFINISHED)->count();
    }

    public function label(): string
    {
        return strtoupper($this->kind);
    }
}
