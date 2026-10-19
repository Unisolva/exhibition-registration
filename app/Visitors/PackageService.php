<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Visitors;

use App\Models\User;
use App\Models\Visitor;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The welcome-package status (M9 CO-10, CO-11). Marking and undoing never go
 * through the record lock: package status may change on a visitor locked
 * after a LIVE push, while edit and trash stay refused. Package data is never
 * sent to the ERP and never touches payload_hash.
 */
class PackageService
{
    public const UNDO_MINUTES = 10;

    /**
     * Records the hand-over. A second press finds it done and records nothing.
     *
     * @return bool whether this call recorded it
     */
    public function give(int $visitorId, User $user): bool
    {
        return DB::transaction(function () use ($visitorId, $user) {
            $visitor = Visitor::query()->whereKey($visitorId)->lockForUpdate()->firstOrFail();
            if ($visitor->package_received_at !== null) {
                return false;
            }
            $this->markGiven($visitor, $user);

            return true;
        });
    }

    /** Inside a caller's transaction (Save & New's "Package given"). */
    public function markGiven(Visitor $visitor, User $user): void
    {
        $visitor->forceFill(['package_received_at' => now(), 'package_received_by' => $user->id])->save();
        Audit::record('package.given', $visitor, userId: $user->id);
    }

    /** The marker within 10 minutes, admins and super admins any time (M9 decision 8). */
    public function canUndo(Visitor $visitor, User $user): bool
    {
        if ($visitor->package_received_at === null) {
            return false;
        }

        return $user->isManager()
            || ($visitor->package_received_by === $user->id && now()->lessThan($visitor->package_received_at->copy()->addMinutes(self::UNDO_MINUTES)));
    }

    public function undo(int $visitorId, User $user): void
    {
        DB::transaction(function () use ($visitorId, $user) {
            $visitor = Visitor::query()->whereKey($visitorId)->lockForUpdate()->firstOrFail();
            if ($visitor->package_received_at === null) {
                return;
            }
            if (! $this->canUndo($visitor, $user)) {
                throw new AccessDeniedHttpException('Only the person who marked it, within 10 minutes, or an admin can undo this.');
            }
            $visitor->forceFill(['package_received_at' => null, 'package_received_by' => null])->save();
            Audit::record('package.undone', $visitor, userId: $user->id);
        });
    }
}
