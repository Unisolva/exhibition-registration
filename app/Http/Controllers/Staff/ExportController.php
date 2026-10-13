<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\FieldDefinition;
use App\Models\Visitor;
use App\Support\Audit;
use App\Support\Settings;
use App\Visitors\VisitorQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export (decision D6): per exhibition or the current filter, UTF-8 with
 * a BOM so spreadsheet programs show Arabic, all system and custom fields,
 * sync state and ERP lead ID. Trashed and deleted visitors are never included.
 */
class ExportController extends Controller
{
    public function __invoke(Request $request, VisitorQuery $visitorQuery, Settings $settings): StreamedResponse
    {
        $filters = $visitorQuery->filters($request);
        $query = $visitorQuery->build($request->user(), $filters);
        $fields = FieldDefinition::query()->orderBy('sort_order')->orderBy('id')->get();
        $custom = $fields->where('is_system', false)->values();
        $occupation = $fields->firstWhere('key', 'occupation');
        $timezone = (string) $settings->get('display_timezone');

        Audit::record('export_downloaded', meta: ['filters' => array_filter($filters, fn ($v) => $v !== '' && $v !== null && $v !== false)]);

        $filename = 'visitors-'.now($timezone)->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query, $custom, $occupation, $timezone) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");

            $header = ['ID', 'Exhibition', 'Registered at', 'Source', 'Entered by', 'Name', 'Phone (as entered)', 'Phone (E.164)',
                'Phone valid', 'Email', 'Occupation', 'Governorate code', 'Governorate', 'Possible duplicate of', 'Edited at'];
            foreach ($custom as $field) {
                $header[] = $field->label_en;
            }
            $header[] = 'Sync state';
            $header[] = 'ERP lead ID';
            fputcsv($out, $header, escape: '');

            foreach ($query->lazy(500) as $visitor) {
                /** @var Visitor $visitor */
                $row = [
                    $visitor->id,
                    $visitor->exhibition?->name,
                    $visitor->created_at?->timezone($timezone)->format('Y-m-d H:i'),
                    $visitor->source,
                    $visitor->enteredBy?->name,
                    $visitor->name,
                    $visitor->phone_raw,
                    $visitor->phone_e164,
                    $visitor->phone_valid ? 'yes' : 'no',
                    $visitor->email,
                    $occupation?->optionLabel($visitor->occupation, 'en'),
                    $visitor->region_code,
                    $visitor->region_label,
                    $visitor->possible_duplicate_of,
                    $visitor->edited_at?->timezone($timezone)->format('Y-m-d H:i'),
                ];
                foreach ($custom as $field) {
                    $value = $visitor->extra[$field->key] ?? null;
                    $row[] = $field->type === 'select' ? $field->optionLabel($value, 'en') : $value;
                }
                $row[] = $visitor->getAttribute('sync_state');
                $row[] = $visitor->getAttribute('sync_erp_lead_id');

                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A value a spreadsheet will not run as a formula. Phone-like values such
     * as +201001234567 stay as they are.
     */
    public static function cell(mixed $value): string
    {
        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $risky = Str::startsWith($text, ['=', '@', "\t", "\r"])
            || (Str::startsWith($text, ['+', '-']) && preg_match('/^[+-][\d\s().-]*$/', $text) !== 1);

        return $risky ? "'".$text : $text;
    }
}
