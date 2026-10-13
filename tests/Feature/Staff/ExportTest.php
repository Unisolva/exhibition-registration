<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace Tests\Feature\Staff;

use App\Http\Controllers\Staff\ExportController;
use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ErpRows;
use Tests\TestCase;

/**
 * Work order §7 "Export" (D6).
 */
class ExportTest extends TestCase
{
    use ErpRows;
    use RefreshDatabase;

    /**
     * @return list<list<string>>
     */
    private function rows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = array_filter(explode("\n", substr($csv, 3)), fn ($line) => $line !== '');

        return array_map(fn ($line) => str_getcsv($line, escape: ''), array_values($lines));
    }

    public function test_csv_has_bom_all_fields_sync_state_and_arabic_intact(): void
    {
        $admin = User::factory()->admin()->create();
        FieldDefinition::query()->create([
            'key' => 'crop', 'is_system' => false, 'type' => 'select', 'label_en' => 'Main crop', 'label_ar' => 'المحصول',
            'options' => [['value' => 'wheat', 'label_en' => 'Wheat', 'label_ar' => 'قمح', 'is_active' => true]],
            'is_active' => true, 'sort_order' => 60, 'send_to_erp' => true,
        ]);
        $visitor = Visitor::factory()->create(['name' => 'فاطمة علي', 'extra' => ['crop' => 'wheat']]);
        $this->syncState($visitor, $this->erpConnection('live'), 'succeeded', 4242);

        $response = $this->actingAs($admin)->get('/staff/visitors/export')->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $rows = $this->rows($response->streamedContent());

        $header = $rows[0];
        $this->assertContains('Main crop', $header);
        $this->assertSame(['Sync state', 'ERP lead ID'], array_slice($header, -2));

        $row = array_combine($header, $rows[1]);
        $this->assertSame('فاطمة علي', $row['Name']);
        $this->assertSame('Wheat', $row['Main crop']);
        $this->assertSame('succeeded', $row['Sync state']);
        $this->assertSame('4242', $row['ERP lead ID']);
        $this->assertSame($visitor->phone_e164, $row['Phone (E.164)']);

        $this->assertDatabaseHas('audit_events', ['action' => 'export_downloaded', 'user_id' => $admin->id]);
    }

    public function test_export_follows_the_filter_and_excludes_trashed_and_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        [$a, $b] = Exhibition::factory()->count(2)->create();
        Visitor::factory()->at($a)->create(['name' => 'In A']);
        Visitor::factory()->at($b)->create(['name' => 'In B']);
        Visitor::factory()->at($a)->trashed($admin)->create(['name' => 'Trashed In A']);

        $csv = $this->actingAs($admin)->get('/staff/visitors/export?exhibition='.$a->id)->streamedContent();

        $this->assertStringContainsString('In A', $csv);
        $this->assertStringNotContainsString('In B', $csv);
        $this->assertStringNotContainsString('Trashed', $csv);
        $this->assertCount(2, $this->rows($csv));
    }

    public function test_cells_cannot_become_spreadsheet_formulas_but_phone_numbers_stay(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", ExportController::cell('=HYPERLINK("http://x")'));
        $this->assertSame("'@SUM(A1)", ExportController::cell('@SUM(A1)'));
        $this->assertSame("'+cmd|' /C calc'!A0", ExportController::cell("+cmd|' /C calc'!A0"));
        $this->assertSame("'-2+3", ExportController::cell('-2+3'));
        $this->assertSame('+201001234567', ExportController::cell('+201001234567'));
        $this->assertSame('-', ExportController::cell('-'));
        $this->assertSame('Normal', ExportController::cell('Normal'));
    }
}
