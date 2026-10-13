<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;

/**
 * Work order §7 roles table, per registration. "Own entries" means
 * entered_by = the user; public registrations belong to no staff member. The
 * lock rule is checked separately, inside the transaction that writes.
 */
class VisitorPolicy
{
    public function view(User $user, Visitor $visitor): bool
    {
        return $user->isManager() || $this->isOwnEntry($user, $visitor);
    }

    /** Edit, and see the edit log: any registration for managers, own entries for staff. */
    public function update(User $user, Visitor $visitor): bool
    {
        return $this->view($user, $visitor);
    }

    /** Move to trash and restore: super admins and admins; staff never. */
    public function trash(User $user, Visitor $visitor): bool
    {
        return $user->isManager();
    }

    public function forceDelete(User $user, Visitor $visitor): bool
    {
        return $user->isSuperAdmin();
    }

    private function isOwnEntry(User $user, Visitor $visitor): bool
    {
        return $visitor->source === 'staff' && $visitor->entered_by === $user->id;
    }
}
