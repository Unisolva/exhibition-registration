<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Providers;

use App\Models\User;
use App\Session\AnonymousDatabaseSessionHandler;
use App\Support\ClientIp;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stack traces in logs must not carry call arguments (names, phones, keys).
        ini_set('zend.exception_ignore_args', '1');

        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        Session::extend('database', fn ($app) => new AnonymousDatabaseSessionHandler(
            $app['db']->connection($app['config']->get('session.connection')),
            $app['config']->get('session.table'),
            $app['config']->get('session.lifetime'),
            $app,
        ));

        // Work order §7: 5 per minute per (email, client IP) and 30 per minute per client IP.
        RateLimiter::for('login', function (Request $request) {
            $ip = ClientIp::of($request);
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('login:'.$email.'|'.$ip),
                Limit::perMinute(30)->by('login-ip:'.$ip),
            ];
        });

        Password::defaults(fn () => Password::min(10)->max(128));

        $this->defineRoleGates();

        View::composer('*', function ($view) {
            $view->with('cspNonce', app()->bound('csp_nonce') ? app('csp_nonce') : '');
        });
    }

    /**
     * The areas of the work order §7 roles table. Per-record rules (own
     * entries, locks, managing super admins) live in the policies.
     */
    private function defineRoleGates(): void
    {
        Gate::define('system.manage', fn (User $user) => $user->isSuperAdmin());
        Gate::define('users.manage', fn (User $user) => $user->isManager());
        Gate::define('exhibitions.manage', fn (User $user) => $user->isManager());
        Gate::define('registrations.view-all', fn (User $user) => $user->isManager());
        Gate::define('registrations.push', fn (User $user) => $user->isManager());
        Gate::define('registrations.export', fn (User $user) => $user->isManager());
        Gate::define('registrations.enter', fn (User $user) => $user->is_active);
        Gate::define('trash.purge', fn (User $user) => $user->isSuperAdmin());
    }
}
