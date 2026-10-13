<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use Illuminate\Support\Facades\DB;

/**
 * Possible-duplicate flags (work order §6 step 7): within one exhibition, every
 * current visitor sharing a phone key points at the earliest of them; the
 * earliest points at nothing. Trashed and deleted visitors take no part.
 */
class DuplicateFlags
{
    /**
     * Recompute the flags of the current visitors of one exhibition whose
     * phone key is one of the given keys.
     *
     * @param  list<string|null>  $phoneKeys
     */
    public static function recompute(int $exhibitionId, array $phoneKeys): void
    {
        $keys = array_values(array_unique(array_filter($phoneKeys, fn ($key) => is_string($key) && $key !== '')));
        if ($keys === []) {
            return;
        }

        $visitors = DB::table('visitors')
            ->where('exhibition_id', $exhibitionId)
            ->whereIn('phone_key', $keys)
            ->whereNull('trashed_at')->whereNull('deleted_at')
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'phone_key', 'possible_duplicate_of']);

        foreach ($visitors->groupBy('phone_key') as $group) {
            $earliest = $group->first()->id;
            foreach ($group as $visitor) {
                $flag = $visitor->id === $earliest ? null : $earliest;
                if ($visitor->possible_duplicate_of !== $flag) {
                    DB::table('visitors')->where('id', $visitor->id)->update(['possible_duplicate_of' => $flag]);
                }
            }
        }
    }

    /**
     * Visitors pointing at a visitor that left the current set (trashed or
     * deleted) get their flag recomputed too.
     */
    public static function releaseReferencesTo(int $visitorId): void
    {
        $pointing = DB::table('visitors')->where('possible_duplicate_of', $visitorId)->get(['exhibition_id', 'phone_key']);
        DB::table('visitors')->where('possible_duplicate_of', $visitorId)->update(['possible_duplicate_of' => null]);

        foreach ($pointing->groupBy('exhibition_id') as $exhibitionId => $group) {
            self::recompute((int) $exhibitionId, $group->pluck('phone_key')->all());
        }
    }
}
