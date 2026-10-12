<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Role $role
 * @property bool $is_active
 * @property bool $must_change_password
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'role', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isStaff(): bool
    {
        return $this->role === Role::Staff;
    }

    /** Super admin or admin: the roles that manage exhibitions and registrations. */
    public function isManager(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin();
    }

    /**
     * End every way this user is signed in: database sessions and remember-me
     * cookies (by rotating the remember token). $keepSessionId keeps the
     * caller's own session, for a user changing their own password.
     */
    public function revokeCredentials(?string $keepSessionId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->id)
            ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
            ->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->save();
    }

    public static function activeSuperAdminCount(): int
    {
        return static::query()->where('role', Role::SuperAdmin)->where('is_active', true)->count();
    }
}
