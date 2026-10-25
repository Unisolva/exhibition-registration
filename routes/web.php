<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\LogoController;
use App\Http\Controllers\PublicRegistrationController;
use App\Http\Controllers\Staff\BatchController;
use App\Http\Controllers\Staff\BrandingController;
use App\Http\Controllers\Staff\BulkTrashController;
use App\Http\Controllers\Staff\EntryController;
use App\Http\Controllers\Staff\ErpController;
use App\Http\Controllers\Staff\ExhibitionController;
use App\Http\Controllers\Staff\ExportController;
use App\Http\Controllers\Staff\FieldController;
use App\Http\Controllers\Staff\HomeController;
use App\Http\Controllers\Staff\ItemController;
use App\Http\Controllers\Staff\PackageController;
use App\Http\Controllers\Staff\SettingsController;
use App\Http\Controllers\Staff\SystemController;
use App\Http\Controllers\Staff\TrashController;
use App\Http\Controllers\Staff\UserController;
use App\Http\Controllers\Staff\VisitorController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/staff');

// Versioned asset names; the web server rewrites these itself, this route is the fallback.
Route::get('assets/{file}', AssetController::class)
    ->where('file', '(vendor/[a-z0-9-]+/)?[a-z0-9-]+(\.min)?\.[0-9a-f]{10}\.(css|js)')
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

        // Fresh CSRF token for Save & New after signing in again in another tab.
        Route::get('session', [EntryController::class, 'session'])->name('staff.session');

        Route::middleware('password.changed')->group(function () {
            Route::get('/', HomeController::class)->name('staff.home');

            // Save & New (every role).
            Route::get('entry', [EntryController::class, 'show'])->name('staff.entry')->can('registrations.enter');
            Route::post('registrations', [EntryController::class, 'store'])->name('staff.registrations.store')->can('registrations.enter');

            // Package hand-over (M9 CO-8, CO-9): every role, by code or exact phone only.
            Route::middleware('can:packages.handover')->group(function () {
                Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
                Route::get('packages/find', [PackageController::class, 'find'])->name('packages.find');
                Route::get('packages/phone', [PackageController::class, 'phone'])->name('packages.phone')->middleware('throttle:package-lookups');
                Route::get('packages/{code}', [PackageController::class, 'show'])->name('packages.show')->middleware('throttle:package-lookups');
                Route::post('packages/{code}/give', [PackageController::class, 'give'])->name('packages.give');
                Route::post('packages/{code}/undo', [PackageController::class, 'undo'])->name('packages.undo');
            });

            // Visitors: staff see their own entries; the policy decides per record.
            Route::get('visitors', [VisitorController::class, 'index'])->name('visitors.index');
            Route::get('visitors/export', ExportController::class)->name('visitors.export')->can('registrations.export');
            Route::whereNumber('visitor')->group(function () {
                Route::get('visitors/{visitor}', [VisitorController::class, 'show'])->name('visitors.show');
                Route::get('visitors/{visitor}/edit', [VisitorController::class, 'edit'])->name('visitors.edit');
                Route::put('visitors/{visitor}', [VisitorController::class, 'update'])->name('visitors.update');
                Route::get('visitors/{visitor}/delete', [VisitorController::class, 'confirmTrash'])->name('visitors.trash-confirm');
                Route::delete('visitors/{visitor}', [VisitorController::class, 'destroy'])->name('visitors.destroy');

                Route::middleware('can:registrations.view-all')->group(function () {
                    // M10 CO-8: managers only.
                    Route::post('visitors/bulk-trash/review', [BulkTrashController::class, 'review'])->name('visitors.bulk-trash.review');
                    Route::post('visitors/bulk-trash', [BulkTrashController::class, 'destroy'])->name('visitors.bulk-trash');
                    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
                    Route::post('trash/{visitor}/restore', [TrashController::class, 'restore'])->name('trash.restore');
                    Route::delete('trash/{visitor}', [TrashController::class, 'destroy'])->name('trash.destroy');
                });
            });

            Route::get('users', [UserController::class, 'index'])->name('users.index')->can('viewAny', User::class);
            Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('create', User::class);
            Route::post('users', [UserController::class, 'store'])->name('users.store')->can('create', User::class);
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('update', 'user');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('update', 'user');
            Route::get('users/{user}/reset-password', [UserController::class, 'confirmResetPassword'])->name('users.reset-password-confirm')->can('resetPassword', 'user');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password')->can('resetPassword', 'user');

            Route::middleware('can:exhibitions.manage')->group(function () {
                Route::get('exhibitions', [ExhibitionController::class, 'index'])->name('exhibitions.index');
                Route::get('exhibitions/create', [ExhibitionController::class, 'create'])->name('exhibitions.create');
                Route::post('exhibitions', [ExhibitionController::class, 'store'])->name('exhibitions.store');
                Route::get('exhibitions/{exhibition}', [ExhibitionController::class, 'show'])->name('exhibitions.show');
                Route::get('exhibitions/{exhibition}/edit', [ExhibitionController::class, 'edit'])->name('exhibitions.edit');
                Route::put('exhibitions/{exhibition}', [ExhibitionController::class, 'update'])->name('exhibitions.update');
                Route::post('exhibitions/{exhibition}/open', [ExhibitionController::class, 'open'])->name('exhibitions.open');
                Route::get('exhibitions/{exhibition}/close', [ExhibitionController::class, 'confirmClose'])->name('exhibitions.close-confirm');
                Route::post('exhibitions/{exhibition}/close', [ExhibitionController::class, 'close'])->name('exhibitions.close');
                Route::get('exhibitions/{exhibition}/qr.svg', [ExhibitionController::class, 'qr'])->name('exhibitions.qr');
                Route::get('exhibitions/{exhibition}/poster', [ExhibitionController::class, 'poster'])->name('exhibitions.poster');
            });

            // Push screens (super admin and admin), absent when ERP mode is "none".
            Route::middleware(['can:registrations.push', 'erp.enabled'])->group(function () {
                Route::get('batches', [BatchController::class, 'index'])->name('batches.index');
                Route::post('batches/preview', [BatchController::class, 'preview'])->name('batches.preview');
                Route::post('batches', [BatchController::class, 'store'])->name('batches.store');
                Route::get('batches/{batch}', [BatchController::class, 'show'])->name('batches.show');
                Route::get('batches/{batch}/status', [BatchController::class, 'status'])->name('batches.status');
                Route::post('batches/{batch}/cancel', [BatchController::class, 'cancel'])->name('batches.cancel');
                Route::post('items/{item}/retry', [ItemController::class, 'retry'])->name('items.retry');
                Route::post('items/{item}/review', [ItemController::class, 'review'])->name('items.review');
            });

            Route::middleware('can:system.manage')->group(function () {
                Route::get('system', SystemController::class)->name('system');
                Route::get('erp', [ErpController::class, 'show'])->name('erp.show');
                Route::put('erp/settings', [ErpController::class, 'updateSettings'])->name('erp.settings');
                Route::put('erp/blocks/{kind}', [ErpController::class, 'saveBlock'])->whereIn('kind', ['test', 'live'])->name('erp.blocks.save');
                Route::post('erp/blocks/{kind}/test', [ErpController::class, 'test'])->whereIn('kind', ['test', 'live'])->name('erp.blocks.test');
                Route::get('erp/activate', [ErpController::class, 'confirmActivate'])->name('erp.activate-confirm');
                Route::post('erp/activate', [ErpController::class, 'activate'])->name('erp.activate');
                Route::put('erp/push', [ErpController::class, 'push'])->name('erp.push');
                Route::post('erp/cancel-waiting', [ErpController::class, 'cancelWaiting'])->name('erp.cancel-waiting');

                Route::get('settings', [SettingsController::class, 'edit'])->name('settings.general');
                Route::put('settings', [SettingsController::class, 'update'])->name('settings.general.update');
                Route::get('settings/branding', [BrandingController::class, 'edit'])->name('settings.branding');
                Route::put('settings/branding', [BrandingController::class, 'update'])->name('settings.branding.update');
                Route::get('settings/fields', [FieldController::class, 'index'])->name('fields.index');
                Route::get('settings/fields/create', [FieldController::class, 'create'])->name('fields.create');
                Route::post('settings/fields', [FieldController::class, 'store'])->name('fields.store');
                Route::get('settings/fields/{field}/edit', [FieldController::class, 'edit'])->name('fields.edit');
                Route::put('settings/fields/{field}', [FieldController::class, 'update'])->name('fields.update');
            });
        });
    });
});
