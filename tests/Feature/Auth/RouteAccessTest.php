<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Auth;

use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Work order §10.6: anonymous requests to every staff route are refused, and a
 * table walks every staff route against the three roles. A new staff route
 * fails this test until it is added to the table, so the table stays complete.
 */
class RouteAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Routes open to guests. */
    private const PUBLIC_STAFF_ROUTES = ['GET staff/login', 'POST staff/login'];

    /**
     * method + uri => [super_admin, admin, staff] expected status.
     * {staff} is a staff user, {super} a super admin, {admin} another admin.
     */
    private const MATRIX = [
        'GET staff' => [200, 200, 200],
        'POST staff/logout' => [302, 302, 302],
        'GET staff/password' => [200, 200, 200],
        'PUT staff/password' => [302, 302, 302],
        'GET staff/users' => [200, 200, 403],
        'GET staff/users/create' => [200, 200, 403],
        'POST staff/users' => [200, 200, 403],
        'GET staff/users/{staff}/edit' => [200, 200, 403],
        'GET staff/users/{super}/edit' => [200, 403, 403],
        'GET staff/users/{admin}/edit' => [200, 200, 403],
        'PUT staff/users/{staff}' => [302, 302, 403],
        'PUT staff/users/{super}' => [302, 403, 403],
        'POST staff/users/{staff}/reset-password' => [200, 200, 403],
        'POST staff/users/{super}/reset-password' => [200, 403, 403],
        'GET staff/exhibitions' => [200, 200, 403],
        'GET staff/exhibitions/create' => [200, 200, 403],
        'POST staff/exhibitions' => [302, 302, 403],
        'GET staff/exhibitions/{exhibition}' => [200, 200, 403],
        'GET staff/exhibitions/{exhibition}/edit' => [200, 200, 403],
        'PUT staff/exhibitions/{exhibition}' => [302, 302, 403],
        'POST staff/exhibitions/{exhibition}/open' => [302, 302, 403],
        'POST staff/exhibitions/{exhibition}/close' => [302, 302, 403],
        'GET staff/exhibitions/{exhibition}/qr.svg' => [200, 200, 403],
        'GET staff/exhibitions/{exhibition}/poster' => [200, 200, 403],
        'GET staff/settings/branding' => [200, 403, 403],
        'PUT staff/settings/branding' => [302, 403, 403],
        'GET staff/entry' => [200, 200, 200],
        'POST staff/registrations' => [302, 302, 302],
        'GET staff/session' => [200, 200, 200],
        'GET staff/visitors' => [200, 200, 200],
        'GET staff/visitors/export' => [200, 200, 403],
        // {visitor} is a public registration: staff never reach it.
        'GET staff/visitors/{visitor}' => [200, 200, 403],
        'GET staff/visitors/{visitor}/edit' => [200, 200, 403],
        'PUT staff/visitors/{visitor}' => [302, 302, 403],
        'GET staff/visitors/{visitor}/delete' => [200, 200, 403],
        'DELETE staff/visitors/{visitor}' => [302, 302, 403],
        'GET staff/trash' => [200, 200, 403],
        'POST staff/trash/{trashed}/restore' => [302, 302, 403],
        'DELETE staff/trash/{trashed}' => [302, 403, 403],
        'GET staff/settings' => [200, 403, 403],
        'PUT staff/settings' => [302, 403, 403],
        'GET staff/settings/fields' => [200, 403, 403],
        'GET staff/settings/fields/create' => [200, 403, 403],
        'POST staff/settings/fields' => [302, 403, 403],
        'GET staff/settings/fields/{field}/edit' => [200, 403, 403],
        'PUT staff/settings/fields/{field}' => [302, 403, 403],
    ];

    public function test_every_staff_route_is_in_the_role_table(): void
    {
        $covered = array_map(
            fn (string $key) => str_replace('{trashed}', '{visitor}', preg_replace('/\{(staff|super|admin)\}/', '{user}', $key)),
            array_keys(self::MATRIX),
        );

        foreach ($this->staffRoutes() as $key) {
            $this->assertContains($key, $covered, "Staff route [{$key}] is missing from the role table.");
        }
    }

    public function test_anonymous_requests_to_every_staff_route_go_to_sign_in(): void
    {
        $target = User::factory()->staff()->create();
        $exhibition = Exhibition::factory()->create();
        $visitor = Visitor::factory()->create();
        $field = FieldDefinition::query()->first();

        foreach ($this->staffRoutes() as $key) {
            [$method, $uri] = explode(' ', $key, 2);
            $url = '/'.str_replace(
                ['{user}', '{exhibition}', '{visitor}', '{field}'],
                [(string) $target->id, (string) $exhibition->id, (string) $visitor->id, (string) $field->id],
                $uri,
            );

            $this->newProcess();
            $this->call($method, $url)->assertRedirect(route('login'));

            $this->newProcess();
            $this->json($method, $url)->assertUnauthorized();
        }
    }

    public function test_the_role_table_holds_for_every_route(): void
    {
        $roles = ['super_admin', 'admin', 'staff'];

        foreach (self::MATRIX as $key => $expected) {
            foreach ($roles as $index => $role) {
                $actor = User::factory()->create(['role' => $role]);
                $targets = [
                    'staff' => User::factory()->staff()->create(),
                    'super' => User::factory()->superAdmin()->create(),
                    'admin' => User::factory()->admin()->create(),
                ];

                $exhibition = Exhibition::factory()->create();
                $visitor = Visitor::factory()->at($exhibition)->create();
                $trashed = Visitor::factory()->at($exhibition)->trashed()->create();
                $field = FieldDefinition::query()->where('key', 'occupation')->first();

                [$method, $uri] = explode(' ', $key, 2);
                $url = '/'.preg_replace_callback('/\{(staff|super|admin)\}/', fn ($m) => (string) $targets[$m[1]]->id, $uri);
                $url = str_replace(
                    ['{exhibition}', '{visitor}', '{trashed}', '{field}'],
                    [(string) $exhibition->id, (string) $visitor->id, (string) $trashed->id, (string) $field->id],
                    $url,
                );
                $data = $this->payloadFor($key, $targets, $exhibition, $visitor);

                $this->newProcess();
                $status = $this->actingAs($actor)->call($method, $url, $data)->getStatusCode();

                $this->assertSame($expected[$index], $status, "{$role} on [{$key}]");
            }
        }
    }

    /**
     * @return list<string> "METHOD uri" for every staff route except the guest ones
     */
    private function staffRoutes(): array
    {
        $routes = [];
        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'staff')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.$route->uri();
                if (! in_array($key, self::PUBLIC_STAFF_ROUTES, true)) {
                    $routes[] = $key;
                }
            }
        }

        return $routes;
    }

    /**
     * A valid body for routes that change data, so the expected status is about
     * permission and not about validation.
     *
     * @param  array<string, User>  $targets
     * @return array<string, string>
     */
    private function payloadFor(string $key, array $targets, Exhibition $exhibition, Visitor $visitor): array
    {
        return match (true) {
            $key === 'POST staff/registrations' => ['exhibition_id' => (string) $exhibition->id, 'submission_token' => (string) Str::ulid(),
                'name' => 'Matrix Visitor', 'phone' => '01001234567'],
            $key === 'PUT staff/visitors/{visitor}' => ['name' => 'Matrix Edit', 'phone' => $visitor->phone_raw, 'governorate' => 'C'],
            $key === 'DELETE staff/trash/{trashed}' => ['confirm' => 'DELETE'],
            $key === 'PUT staff/settings' => ['default_country' => 'EG', 'display_timezone' => 'Africa/Cairo', 'retention_months' => '24'],
            $key === 'POST staff/settings/fields' => ['key' => 'm'.substr(uniqid(), -8), 'type' => 'text', 'label_en' => 'M', 'label_ar' => 'م', 'sort_order' => '99'],
            $key === 'PUT staff/settings/fields/{field}' => ['label_en' => 'Occupation', 'label_ar' => 'المهنة', 'sort_order' => '40'],
            $key === 'POST staff/users' => ['name' => 'Matrix User', 'email' => uniqid('m').'@example.test', 'role' => 'staff'],
            $key === 'POST staff/exhibitions', $key === 'PUT staff/exhibitions/{exhibition}' => ['name' => 'Matrix Fair', 'starts_on' => '2026-11-01'],
            $key === 'PUT staff/settings/branding' => ['brand_color' => '#1F4E79'],
            str_starts_with($key, 'PUT staff/users/') => (function () use ($key, $targets) {
                $target = $targets[str_contains($key, '{super}') ? 'super' : 'staff'];

                return ['name' => $target->name.' x', 'email' => $target->email, 'role' => $target->role->value, 'is_active' => '1'];
            })(),
            default => [],
        };
    }
}
