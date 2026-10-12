<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Writes audit_events rows (work order §4). Meta stays small and never holds
 * visitor personal data, passwords or keys.
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        string $action,
        ?Model $target = null,
        string $outcome = 'ok',
        array $meta = [],
        ?int $userId = null,
    ): void {
        DB::table('audit_events')->insert([
            'at' => now(),
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'target_type' => $target ? self::targetType($target) : null,
            'target_id' => $target?->getKey(),
            'outcome' => $outcome,
            'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function targetType(Model $model): string
    {
        return $model->getTable();
    }
}
