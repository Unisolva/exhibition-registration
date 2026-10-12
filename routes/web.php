<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Staff\HomeController;
use App\Http\Controllers\Staff\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/staff');

// Versioned asset names; the web server rewrites these itself, this route is the fallback.
Route::get('assets/{file}', AssetController::class)
    ->where('file', '[a-z0-9-]+\.[0-9a-f]{10}\.(css|js)')
    ->name('asset');

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
        });
    });
});
