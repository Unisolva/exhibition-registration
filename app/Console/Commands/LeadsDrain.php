<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Console\Commands;

use App\Erp\Drain;
use Illuminate\Console\Command;

/**
 * Run every minute by cron (work order §3). Bounded by --max-items and
 * --max-seconds; never a resident worker.
 */
class LeadsDrain extends Command
{
    protected $signature = 'leads:drain {--max-items=25 : Items per run} {--max-seconds=45 : Seconds per run}';

    protected $description = 'Push queued leads to the ERP in one bounded run';

    public function handle(Drain $drain): int
    {
        $result = $drain->run(
            max(1, (int) $this->option('max-items')),
            max(1, (int) $this->option('max-seconds')),
        );
        $this->line(json_encode($result));

        return $result['outcome'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
