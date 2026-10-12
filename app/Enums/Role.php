<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Admin => 'Admin',
            self::Staff => 'Staff',
        };
    }

    /**
     * Roles the given actor may assign or manage (work order §7).
     *
     * @return list<self>
     */
    public static function manageableBy(self $actor): array
    {
        return match ($actor) {
            self::SuperAdmin => [self::SuperAdmin, self::Admin, self::Staff],
            self::Admin => [self::Admin, self::Staff],
            self::Staff => [],
        };
    }
}
