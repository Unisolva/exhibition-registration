<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests;

use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Tests using RefreshDatabase start from the product seed (fields, regions, settings). */
    protected bool $seed = true;

    protected string $seeder = ProductSeeder::class;

    /** Tests that expect a 500 set this; otherwise any 5xx fails the test at once. */
    protected bool $allowServerErrors = false;

    /**
     * A server error never passes silently: content assertions such as
     * assertDontSee could otherwise succeed on a debug error page.
     */
    protected function createTestResponse($response, $request)
    {
        $testResponse = parent::createTestResponse($response, $request);

        if (! $this->allowServerErrors && $testResponse->getStatusCode() >= 500) {
            $exception = $testResponse->exception;
            $this->fail(sprintf(
                'Unexpected %d for %s %s%s',
                $testResponse->getStatusCode(),
                $request->getMethod(),
                $request->getRequestUri(),
                $exception ? ': '.get_class($exception).': '.$exception->getMessage() : '',
            ));
        }

        return $testResponse;
    }

    /**
     * Forget the signed-in user and session store held in memory, so the next
     * request behaves like a new PHP process that only knows its cookies.
     */
    protected function newProcess(): void
    {
        // Singletons that hold the previous request's guard or session store.
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('redirect');
        Facade::clearResolvedInstances();
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    /**
     * Sign in through the real login form and return the cookies a browser would keep.
     *
     * @return array<string, string>
     */
    protected function browserLogin(User $user, bool $remember = false, string $password = UserFactory::PASSWORD): array
    {
        $this->newProcess();
        $response = $this->post('/staff/login', [
            'email' => $user->email,
            'password' => $password,
            'remember' => $remember ? '1' : null,
        ]);
        $response->assertRedirect();

        return $this->cookiesFrom($response);
    }

    /**
     * @return array<string, string>
     */
    protected function cookiesFrom(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'XSRF-TOKEN') {
                continue;
            }
            $cookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
        }

        return $cookies;
    }

    /**
     * Send a GET as a fresh process carrying only the given cookies.
     *
     * @param  array<string, string>  $cookies
     */
    protected function getWithCookies(string $uri, array $cookies): TestResponse
    {
        $this->newProcess();

        return $this->withCookies($cookies)->get($uri);
    }

    protected function rememberCookieName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }
}
