<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Email and password sign-in, written by hand (no starter kit). No self-signup
 * and no email reset: the app sends no email.
 */
class LoginController extends Controller
{
    /** Bcrypt hash of a discarded random value; checked against when the email is unknown. */
    private const UNKNOWN_USER_HASH = '$2y$12$RmQL3yV02RkDccfSDJBaguq5MI2Zv.MRypxlCDotJIrD5qgsv1yxW';

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();

        // Hash even when the user is unknown, so timing does not reveal which emails exist.
        $passwordOk = Hash::check($data['password'], $user?->password ?? self::UNKNOWN_USER_HASH);

        if ($user === null || ! $passwordOk || ! $user->is_active) {
            Audit::record('login_failed', $user, 'denied', userId: $user?->id);

            throw ValidationException::withMessages([
                'email' => 'These details do not match an active account.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate(true);
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::record('login', $user);

        return redirect()->intended(route('staff.home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record('logout', $request->user());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
