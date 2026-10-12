<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.password', ['forced' => $request->user()->must_change_password]);
    }

    /**
     * Work order §16.8: changing one's own password keeps the current session
     * (under a new ID) and ends every other session and remember cookie.
     */
    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);

        $user = $request->user();
        $guard = Auth::guard('web');
        $hadRememberCookie = $request->cookies->has($guard->getRecallerName());

        $user->forceFill([
            'password' => $request->input('password'),
            'must_change_password' => false,
        ])->save();

        $user->revokeCredentials(keepSessionId: $request->session()->getId());

        if ($hadRememberCookie) {
            // Re-issue this device's remember cookie with the new token (also migrates the session).
            $guard->login($user, true);
        } else {
            $request->session()->regenerate(true);
        }

        Audit::record('password_changed', $user);

        return redirect()->route('staff.home')->with('status', 'Your password has been changed.');
    }
}
