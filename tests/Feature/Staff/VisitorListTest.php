<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Models\Exhibition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ErpRows;
use Tests\TestCase;

/**
 * Work order §7 "Visitor list": keyset pages of 50, search, filters, sorting.
 */
class VisitorListTest extends TestCase
{
    use ErpRows;
    use RefreshDatabase;

    /**
     * @return list<int>
     */
    private function idsOn(string $html): array
    {
        preg_match_all('#/staff/visitors/(\d+)"#', $html, $m);

        return array_map('intval', array_values(array_unique($m[1])));
    }

    public function test_pages_hold_50_and_follow_on_without_gaps_or_repeats(): void
    {
        $admin = User::factory()->admin()->create();
        $exhibition = Exhibition::factory()->create();
        $base = now()->subDay();
        for ($i = 0; $i < 120; $i++) {
            // Pairs share a timestamp, so the id tie-breaker matters.
            Visitor::factory()->at($exhibition)->create(['created_at' => $base->copy()->addMinutes(intdiv($i, 2))]);
        }

        $seen = [];
        $url = '/staff/visitors';
        $pages = 0;
        while ($url !== null && $pages < 5) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $ids = $this->idsOn($html);
            $this->assertLessThanOrEqual(50, count($ids));
            $seen = [...$seen, ...$ids];
            preg_match('#href="([^"]*cursor=[^"]+)">Next#', $html, $m);
            $url = isset($m[1]) ? html_entity_decode($m[1]) : null;
            $pages++;
        }

        $this->assertSame(3, $pages);
        $this->assertCount(120, $seen);
        $this->assertCount(120, array_unique($seen));
        $expected = Visitor::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
        $this->assertSame($expected, $seen);
    }

    public function test_search_by_name_prefix_and_by_phone_in_any_format(): void
    {
        $admin = User::factory()->admin()->create();
        Visitor::factory()->create(['name' => 'محمود سعيد', 'phone_raw' => '01007654321', 'phone_key' => '+201007654321']);
        Visitor::factory()->create(['name' => 'Samir Adel']);

        $this->actingAs($admin)->get('/staff/visitors?q='.urlencode('محمود'))->assertOk()->assertSee('محمود سعيد')->assertDontSee('Samir Adel');
        $this->actingAs($admin)->get('/staff/visitors?q=Sam')->assertOk()->assertSee('Samir Adel')->assertDontSee('محمود سعيد');
        $this->actingAs($admin)->get('/staff/visitors?q=Adel')->assertOk()->assertDontSee('Samir Adel');

        foreach (['01007654321', '+20 100 765 4321', '٠١٠٠٧٦٥٤٣٢١'] as $phone) {
            $this->actingAs($admin)->get('/staff/visitors?q='.urlencode($phone))->assertOk()->assertSee('محمود سعيد')->assertDontSee('Samir Adel');
        }

        // A % in the search is a literal character, not a wildcard.
        $this->actingAs($admin)->get('/staff/visitors?q=%25')->assertOk()->assertDontSee('Samir Adel');
    }

    public function test_filters_combine(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        [$a, $b] = Exhibition::factory()->count(2)->create();
        Visitor::factory()->at($a)->create(['name' => 'A Public Cairo', 'region_code' => 'C']);
        Visitor::factory()->at($a)->enteredBy($staff)->create(['name' => 'A Staff Giza', 'region_code' => 'GZ']);
        Visitor::factory()->at($b)->create(['name' => 'B Public Cairo', 'region_code' => 'C', 'possible_duplicate_of' => null]);
        $dup = Visitor::factory()->at($b)->create(['name' => 'B Duplicate']);
        $dup->forceFill(['possible_duplicate_of' => Visitor::query()->where('name', 'B Public Cairo')->value('id')])->save();

        $get = fn (string $query) => $this->actingAs($admin)->get('/staff/visitors?'.$query)->assertOk();

        $get("exhibition={$a->id}")->assertSee('A Public Cairo')->assertSee('A Staff Giza')->assertDontSee('B Public Cairo');
        $get("exhibition={$a->id}&source=staff")->assertSee('A Staff Giza')->assertDontSee('A Public Cairo');
        $get('region=C')->assertSee('A Public Cairo')->assertSee('B Public Cairo')->assertDontSee('A Staff Giza');
        $get('duplicate=1')->assertSee('B Duplicate')->assertDontSee('B Public Cairo');
        $get('source=bogus&sort=password')->assertOk()->assertSee('A Public Cairo');
    }

    public function test_sync_state_filter_uses_the_live_connection_when_none_is_active(): void
    {
        $admin = User::factory()->admin()->create();
        $sent = Visitor::factory()->create(['name' => 'Sent Visitor']);
        Visitor::factory()->create(['name' => 'Never Sent']);
        $this->syncState($sent, $this->erpConnection('live'), 'succeeded', 12);

        $this->actingAs($admin)->get('/staff/visitors?sync=succeeded')->assertOk()->assertSee('Sent Visitor')->assertDontSee('Never Sent');
        $this->actingAs($admin)->get('/staff/visitors?sync=never')->assertOk()->assertSee('Never Sent')->assertDontSee('Sent Visitor');
    }

    public function test_sort_by_name(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
            Visitor::factory()->create(['name' => $name]);
        }

        $html = $this->actingAs($admin)->get('/staff/visitors?sort=name')->getContent();
        $this->assertTrue(strpos($html, 'Alpha') < strpos($html, 'Bravo') && strpos($html, 'Bravo') < strpos($html, 'Charlie'));
    }
}
