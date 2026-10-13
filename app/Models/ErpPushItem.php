<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One visitor in one batch (work order §4 erp_push_items). Its payload is
 * the snapshot built server-side when it was queued.
 *
 * @property int $id
 * @property int $batch_id
 * @property int $visitor_id
 * @property int $connection_id
 * @property array<string, mixed> $payload
 * @property string $state
 * @property int $attempts
 * @property string|null $error_class
 * @property string|null $error_message
 * @property int|null $erp_lead_id
 */
class ErpPushItem extends Model
{
    public const STATES = ['queued', 'processing', 'succeeded', 'failed_retry', 'failed_final', 'needs_review', 'cancelled'];

    /** States that keep a visitor locked and block connection changes. */
    public const UNFINISHED = ['queued', 'processing', 'failed_retry', 'needs_review'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'erp_lead_id' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ErpBatch::class, 'batch_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ErpConnection::class, 'connection_id');
    }
}
