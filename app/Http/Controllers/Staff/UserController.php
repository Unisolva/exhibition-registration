<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\OneTimePassword;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Work order §7 "Users". A new user and a reset user get a generated one-time
 * password, shown once in the response and never stored in the session.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $actor = $request->user();

        $users = User::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('role', '!=', Role::SuperAdmin))
            ->orderBy('name')
            ->get();

        return view('staff.users.index', ['users' => $users]);
    }

    public function create(Request $request): View
    {
        return view('staff.users.form', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::manageableBy($request->user()->role),
        ]);
    }

    public function store(Request $request): View
    {
        $data = $this->validated($request, null);
        Gate::authorize('assignRole', [User::class, $data['role']]);

        $password = OneTimePassword::generate();
        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => true,
        ]);
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();

        Audit::record('user_created', $user, meta: ['role' => $user->role->value]);

        return view('staff.users.password', ['user' => $user, 'password' => $password, 'created' => true]);
    }

    public function edit(Request $request, User $user): View
    {
        return view('staff.users.form', [
            'user' => $user,
            'roles' => Role::manageableBy($request->user()->role),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $actor = $request->user();
        $data = $this->validated($request, $user);
        $active = $request->boolean('is_active');

        if ($data['role'] !== $user->role) {
            Gate::authorize('assignRole', [User::class, $data['role']]);
            abort_if($actor->is($user) && ! $actor->isSuperAdmin(), 403, 'You cannot change your own role.');
        }

        if ($actor->is($user) && ! $active) {
            throw ValidationException::withMessages(['is_active' => 'You cannot deactivate yourself.']);
        }

        $deactivated = DB::transaction(function () use ($user, $data, $active) {
            // Lock the super admins so two changes cannot both remove "the other" last one.
            $lockedSuperAdmins = User::query()->where('role', Role::SuperAdmin)->where('is_active', true)
                ->orderBy('id')->lockForUpdate()->pluck('id');

            $user->refresh();
            $losesSuperAdmin = $user->isSuperAdmin() && $user->is_active
                && ($data['role'] !== Role::SuperAdmin || ! $active);

            if ($losesSuperAdmin && $lockedSuperAdmins->count() <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'The last active super admin cannot be demoted or deactivated.',
                ]);
            }

            $wasActive = $user->is_active;
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'is_active' => $active,
            ]);
            $changed = array_keys($user->getDirty());
            $user->save();

            if ($wasActive && ! $active) {
                $user->revokeCredentials();
                Audit::record('user_deactivated', $user);

                return true;
            }

            if ($changed !== []) {
                Audit::record('user_updated', $user, meta: ['fields' => $changed]);
            }

            return false;
        });

        return redirect()->route('users.index')
            ->with('status', $deactivated ? "{$user->name} was deactivated and signed out everywhere." : "{$user->name} was saved.");
    }

    public function resetPassword(User $user): View
    {
        $password = OneTimePassword::generate();
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $user->revokeCredentials();

        Audit::record('password_reset', $user);

        return view('staff.users.password', ['user' => $user, 'password' => $password, 'created' => false]);
    }

    /**
     * @return array{name: string, email: string, role: Role}
     */
    private function validated(Request $request, ?User $user): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        return [
            'name' => trim($data['name']),
            'email' => $data['email'],
            'role' => Role::from($data['role']),
        ];
    }
}
