<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use Illuminate\Support\Facades\DB;

/**
 * The record lock (work order §4): a visitor is locked when it succeeded on a
 * Live connection, or when any of its sync rows has an active push item. A
 * success on a Test connection alone does not lock.
 */
class VisitorLock
{
    public const LIVE = 'live';

    public const ACTIVE = 'active';

    /**
     * Lock cause for one visitor. With $forUpdate the visitor's lead_syncs rows
     * are locked, which the caller does after locking the visitor row (§4 lock order).
     */
    public static function causeFor(int $visitorId, bool $forUpdate = false): ?string
    {
        $syncs = DB::table('lead_syncs')->where('visitor_id', $visitorId)
            ->when($forUpdate, fn ($query) => $query->orderBy('connection_id')->lockForUpdate())
            ->get(['connection_id', 'state', 'active_item_id']);

        if ($syncs->isEmpty()) {
            return null;
        }

        $kinds = DB::table('erp_connections')->whereIn('id', $syncs->pluck('connection_id'))->pluck('kind', 'id');

        return self::fromRows($syncs->map(fn ($s) => (object) [
            'state' => $s->state, 'active_item_id' => $s->active_item_id, 'kind' => $kinds[$s->connection_id] ?? null,
        ])->all());
    }

    /**
     * Lock causes for many visitors at once (list pages), keyed by visitor ID.
     *
     * @param  list<int>  $visitorIds
     * @return array<int, string>
     */
    public static function causesFor(array $visitorIds): array
    {
        if ($visitorIds === []) {
            return [];
        }

        $rows = DB::table('lead_syncs')
            ->join('erp_connections', 'erp_connections.id', '=', 'lead_syncs.connection_id')
            ->whereIn('lead_syncs.visitor_id', $visitorIds)
            ->get(['lead_syncs.visitor_id', 'lead_syncs.state', 'lead_syncs.active_item_id', 'erp_connections.kind']);

        $causes = [];
        foreach ($rows->groupBy('visitor_id') as $visitorId => $group) {
            if ($cause = self::fromRows($group->all())) {
                $causes[(int) $visitorId] = $cause;
            }
        }

        return $causes;
    }

    public static function message(string $cause): string
    {
        return $cause === self::LIVE
            ? 'Already sent to the live ERP'
            : 'Being sent to the ERP; try again when the push finishes';
    }

    /**
     * @param  list<object{state: string, active_item_id: int|null, kind: string|null}>  $rows
     */
    private static function fromRows(array $rows): ?string
    {
        foreach ($rows as $row) {
            if ($row->state === 'succeeded' && $row->kind === 'live') {
                return self::LIVE;
            }
        }
        foreach ($rows as $row) {
            if ($row->active_item_id !== null) {
                return self::ACTIVE;
            }
        }

        return null;
    }
}
