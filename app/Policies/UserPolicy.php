<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Work order §7: a super admin manages every user; an admin manages staff and
 * other admins but never sees, changes, deactivates or resets a super admin.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isManager();
    }

    public function create(User $actor): bool
    {
        return $actor->isManager();
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $actor->isAdmin() && ! $target->isSuperAdmin();
    }

    /** One-time password reset; users change their own password on the password screen. */
    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->update($actor, $target);
    }

    public function assignRole(User $actor, Role $role): bool
    {
        return in_array($role, Role::manageableBy($actor->role), true);
    }
}
