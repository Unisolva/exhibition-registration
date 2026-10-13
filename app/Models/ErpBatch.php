<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A push batch (work order §4 erp_batches). Its status is computed from its
 * items, never stored.
 *
 * @property int $id
 * @property string $batch_token
 * @property int $created_by
 * @property int $connection_id
 * @property list<string> $field_keys
 * @property int $item_count
 */
class ErpBatch extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['field_keys' => 'array'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ErpConnection::class, 'connection_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ErpPushItem::class, 'batch_id');
    }

    /**
     * @return array<string, int> count per item state
     */
    public function totals(): array
    {
        $counts = DB::table('erp_push_items')->where('batch_id', $this->id)
            ->selectRaw('state, COUNT(*) AS n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n)->all();

        return array_merge(array_fill_keys(ErpPushItem::STATES, 0), $counts);
    }

    public function status(): string
    {
        $totals = $this->totals();
        if ($totals['queued'] + $totals['processing'] + $totals['failed_retry'] > 0) {
            return 'in progress';
        }
        if ($totals['needs_review'] > 0) {
            return 'needs review';
        }
        if ($totals['failed_final'] > 0) {
            return 'finished with failures';
        }

        return 'finished';
    }
}
