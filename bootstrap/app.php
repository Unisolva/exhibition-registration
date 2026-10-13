<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\GuardPublicSubmission;
use App\Http\Middleware\RejectUnknownHost;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UsePublicLocale;
use App\Http\Middleware\UseStaffLocale;
use App\Visitors\VisitorLockedException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Outermost first: headers wrap everything, including the 404 for a wrong host.
        $middleware->prepend([
            SecurityHeaders::class,
            AssignRequestId::class,
            RejectUnknownHost::class,
        ]);

        // Host check in production as well (work order §3); local and tests rely on RejectUnknownHost.
        $middleware->trustHosts(subdomains: false);

        // Laravel's web group, with the public submission guard placed after the
        // session starts and before CSRF (work order §6 POST order, steps 1 and 2).
        $middleware->group('web', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            GuardPublicSubmission::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordChanged::class,
            'staff.locale' => UseStaffLocale::class,
            'public.locale' => UsePublicLocale::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('staff.home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson(),
        );

        // Work order §9: logs never hold request data. A query error's message
        // carries its bindings (names, phones), so log the statement shape only.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed', [
                'error_code' => $e->getCode(),
                'sql' => $e->getSql(),
            ]);
        })->stop();

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'api_key']);

        // Work order §4: a locked visitor answers 423 with the cause-specific message.
        $exceptions->render(function (VisitorLockedException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'cause' => $e->cause], 423)
                : response()->view('staff.visitors.locked', ['message' => $e->getMessage()], 423);
        });
        $exceptions->dontReport(VisitorLockedException::class);
    })->create();
