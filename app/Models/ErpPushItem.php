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

    /** Plain words for item states and error classes on staff screens (M8 L6). */
    public const STATE_LABELS = [
        'queued' => 'Waiting',
        'processing' => 'Sending now',
        'succeeded' => 'Sent',
        'failed_retry' => 'Failed, will retry',
        'failed_final' => 'Failed',
        'needs_review' => 'Check in Odoo',
        'cancelled' => 'Cancelled',
    ];

    public const ERROR_LABELS = [
        'network' => 'No connection to Odoo',
        'timeout' => 'Odoo took too long to answer',
        'http_5xx' => 'Odoo server error',
        'rate_limited' => 'Odoo asked to slow down',
        'auth' => 'Login or API key refused',
        'validation' => 'Odoo refused the data',
        'ambiguous' => 'More than one lead with this reference',
        'version' => 'The Odoo version changed',
        'stale_claim' => 'Interrupted; will retry',
        'unknown' => 'Unexpected answer from Odoo',
        'exception' => 'Unexpected error',
    ];

    public static function stateLabel(string $state): string
    {
        return self::STATE_LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state));
    }

    /** Badge colour by meaning (M10 CO-4): sent green, needs checking and retries amber, failed red. */
    public static function stateBadge(string $state): string
    {
        return match ($state) {
            'succeeded' => 'bg-success-lt',
            'needs_review', 'failed_retry' => 'bg-warning-lt',
            'failed_final' => 'bg-danger-lt',
            default => 'bg-secondary-lt',
        };
    }

    public static function errorLabel(string $class): string
    {
        return self::ERROR_LABELS[$class] ?? ucfirst(str_replace('_', ' ', $class));
    }

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
