<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Console\Commands;

use App\Http\Controllers\Staff\TrashController;
use App\Models\Visitor;
use App\Support\Settings;
use App\Visitors\VisitorLockedException;
use App\Visitors\VisitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily cron (work order §8 housekeeping:run). Retention is the only removal
 * that ignores the Live lock; a visitor with an item still in flight is
 * always skipped. Rows, batches and sync history stay without personal data.
 */
class Housekeeping extends Command
{
    protected $signature = 'housekeeping:run';

    protected $description = 'Delete old drain runs, expired sessions, visitors trashed over 30 days ago and visitors past retention';

    public function handle(VisitorService $visitors, Settings $settings): int
    {
        $counts = [
            'drain_runs' => DB::table('drain_runs')->where('started_at', '<', now()->subDays(30))->delete(),
            'sessions' => DB::table('sessions')->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())->delete(),
            'trash_deleted' => 0,
            'retention_deleted' => 0,
            'skipped_in_flight' => 0,
        ];

        $trashIds = Visitor::query()->whereNull('deleted_at')->whereNotNull('trashed_at')
            ->where('trashed_at', '<', now()->subDays(TrashController::DAYS))->orderBy('id')->pluck('id');
        foreach ($trashIds as $id) {
            $this->eraseCounting($visitors, $id, false, 'trash_deleted', $counts);
        }

        $months = max(1, (int) $settings->get('retention_months'));
        $retentionIds = Visitor::query()->whereNull('deleted_at')
            ->where('created_at', '<', now()->subMonths($months))
            ->whereNotExists(fn ($q) => $q->from('lead_syncs')->whereColumn('lead_syncs.visitor_id', 'visitors.id')->whereNotNull('lead_syncs.active_item_id'))
            ->orderBy('id')->pluck('id');
        foreach ($retentionIds as $id) {
            $this->eraseCounting($visitors, $id, true, 'retention_deleted', $counts);
        }

        Log::channel('drain')->info('housekeeping', $counts);
        $this->line(json_encode($counts));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function eraseCounting(VisitorService $visitors, int $id, bool $retention, string $counter, array &$counts): void
    {
        try {
            $visitors->erase($id, null, ignoreLiveLock: $retention);
            $counts[$counter]++;
        } catch (VisitorLockedException) {
            $counts['skipped_in_flight']++;
        }
    }
}
