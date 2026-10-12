<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\Staff\BrandingController;
use App\Http\Controllers\Staff\ExhibitionController;
use App\Http\Controllers\Staff\HomeController;
use App\Http\Controllers\Staff\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/staff');

// Versioned asset names; the web server rewrites these itself, this route is the fallback.
Route::get('assets/{file}', AssetController::class)
    ->where('file', '[a-z0-9-]+\.[0-9a-f]{10}\.(css|js)')
    ->name('asset');

Route::get('logo/{hash}.{ext}', LogoController::class)
    ->where(['hash' => '[0-9a-f]{16}', 'ext' => 'png|svg'])
    ->name('logo');

// Public registration (work order §6). The token is the exhibition's QR token.
Route::middleware('public.locale')->group(function () {
    Route::get('r/{token}', [PublicRegistrationController::class, 'show'])->name('public.show');
    Route::post('r/{token}', [PublicRegistrationController::class, 'submit'])->name('public.submit');
    Route::get('r/{token}/thanks', [PublicRegistrationController::class, 'thanks'])->name('public.thanks');
});

Route::prefix('staff')->middleware('staff.locale')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'show'])->name('login');
        Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
    });

    Route::middleware(['auth', 'active'])->group(function () {
        Route::post('logout', [LoginController::class, 'logout'])->name('logout');
        Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('password', [PasswordController::class, 'update'])->name('password.update');

        Route::middleware('password.changed')->group(function () {
            Route::get('/', HomeController::class)->name('staff.home');

            Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
            Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
            Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password')->can('resetPassword', 'user');

            Route::middleware('can:exhibitions.manage')->group(function () {
                Route::get('exhibitions', [ExhibitionController::class, 'index'])->name('exhibitions.index');
                Route::get('exhibitions/create', [ExhibitionController::class, 'create'])->name('exhibitions.create');
                Route::post('exhibitions', [ExhibitionController::class, 'store'])->name('exhibitions.store');
                Route::get('exhibitions/{exhibition}', [ExhibitionController::class, 'show'])->name('exhibitions.show');
                Route::get('exhibitions/{exhibition}/edit', [ExhibitionController::class, 'edit'])->name('exhibitions.edit');
                Route::put('exhibitions/{exhibition}', [ExhibitionController::class, 'update'])->name('exhibitions.update');
                Route::post('exhibitions/{exhibition}/open', [ExhibitionController::class, 'open'])->name('exhibitions.open');
                Route::post('exhibitions/{exhibition}/close', [ExhibitionController::class, 'close'])->name('exhibitions.close');
                Route::get('exhibitions/{exhibition}/qr.svg', [ExhibitionController::class, 'qr'])->name('exhibitions.qr');
                Route::get('exhibitions/{exhibition}/poster', [ExhibitionController::class, 'poster'])->name('exhibitions.poster');
            });

            Route::middleware('can:system.manage')->group(function () {
                Route::get('settings/branding', [BrandingController::class, 'edit'])->name('settings.branding');
                Route::put('settings/branding', [BrandingController::class, 'update'])->name('settings.branding.update');
            });
        });
    });
});
