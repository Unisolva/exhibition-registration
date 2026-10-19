<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\FieldDefinition;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Field definitions editor (super admin, work order §4 field_definitions).
 * Keys and types never change after creation and nothing is ever deleted:
 * deactivating hides a field from the forms and keeps stored values. Option
 * values are kept too; options can be relabelled, deactivated and added.
 */
class FieldController extends Controller
{
    /** Request keys the forms already use; a custom field may not take them. */
    private const RESERVED_KEYS = [
        'submission_token', 'exhibition_id', 'confirm', 'lang', 'remember',
        'password', 'email_address', 'phone_raw', 'phone_key', 'phone_e164', 'source', 'extra',
    ];

    public function index(): View
    {
        return view('staff.fields.index', [
            'fields' => FieldDefinition::query()->orderBy('sort_order')->orderBy('id')->get(),
            'activeCustom' => $this->activeCustomCount(),
        ]);
    }

    public function create(): View
    {
        return view('staff.fields.form', ['field' => new FieldDefinition([
            'type' => 'text', 'is_active' => true, 'send_to_erp' => true,
            'sort_order' => (int) FieldDefinition::query()->max('sort_order') + 10,
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', 'unique:field_definitions,key',
                Rule::notIn([...FieldDefinition::SYSTEM_KEYS, ...self::RESERVED_KEYS])],
            'type' => ['required', Rule::in(['text', 'select'])],
            ...$this->commonRules($request, isSystem: false),
        ]);

        $active = $request->boolean('is_active');
        if ($active && $this->activeCustomCount() >= FieldDefinition::MAX_ACTIVE_CUSTOM) {
            throw ValidationException::withMessages(['is_active' => 'At most 15 custom fields can be active. Deactivate one first.']);
        }

        $field = FieldDefinition::query()->create([
            'key' => $data['key'],
            'is_system' => false,
            'type' => $data['type'],
            'label_en' => trim($data['label_en']),
            'label_ar' => trim((string) ($data['label_ar'] ?? '')),
            'audience' => $this->audience($request, isSystem: false),
            'widget' => $this->widget($request, $data['type']),
            'options' => $data['type'] === 'select' ? $this->options($request, [], $this->audience($request, isSystem: false) === 'all') : null,
            'is_required' => $request->boolean('is_required'),
            'is_active' => $active,
            'sort_order' => (int) $data['sort_order'],
            'send_to_erp' => $request->boolean('send_to_erp'),
        ]);

        Audit::record('field_created', $field, meta: ['key' => $field->key, 'type' => $field->type]);

        return redirect()->route('fields.index')->with('status', "Field \"{$field->label_en}\" added.");
    }

    public function edit(FieldDefinition $field): View
    {
        return view('staff.fields.form', ['field' => $field]);
    }

    public function update(Request $request, FieldDefinition $field): RedirectResponse
    {
        $data = $request->validate($this->commonRules($request, $field->is_system));

        $alwaysOn = in_array($field->key, ['name', 'phone'], true);
        $active = $alwaysOn || $request->boolean('is_active');
        if (! $field->is_system && $active && ! $field->is_active
            && $this->activeCustomCount() >= FieldDefinition::MAX_ACTIVE_CUSTOM) {
            throw ValidationException::withMessages(['is_active' => 'At most 15 custom fields can be active. Deactivate one first.']);
        }

        $field->fill([
            'label_en' => trim($data['label_en']),
            'label_ar' => trim((string) ($data['label_ar'] ?? '')),
            'audience' => $this->audience($request, $field->is_system),
            'widget' => $this->widget($request, $field->type),
            'is_required' => $alwaysOn || $request->boolean('is_required'),
            'is_active' => $active,
            'sort_order' => (int) $data['sort_order'],
            'send_to_erp' => $request->boolean('send_to_erp'),
        ]);
        if ($field->type === 'select') {
            $field->options = $this->options($request, $field->options ?? [], $this->audience($request, $field->is_system) === 'all');
        }

        $changed = array_keys($field->getDirty());
        $field->save();
        if ($changed !== []) {
            Audit::record('field_updated', $field, meta: ['key' => $field->key, 'fields' => $changed]);
        }

        return redirect()->route('fields.index')->with('status', "Field \"{$field->label_en}\" saved.");
    }

    /**
     * "Shown on" (M9 CO-1): staff-only or everyone; system fields are always shown to everyone.
     */
    private function audience(Request $request, bool $isSystem): string
    {
        return ! $isSystem && $request->input('audience') === 'staff' ? 'staff' : 'all';
    }

    /** "Show as": radio buttons or a dropdown, for list fields only (Governorate and text fields stay as they are). */
    private function widget(Request $request, string $type): string
    {
        return $type === 'select' && $request->input('widget') === 'radio' ? 'radio' : 'select';
    }

    /**
     * The Arabic label is needed only where Arabic is shown: staff screens are English (M9 CO-1).
     *
     * @return array<string, mixed>
     */
    private function commonRules(Request $request, bool $isSystem): array
    {
        return [
            'audience' => ['nullable', Rule::in(['all', 'staff'])],
            'widget' => ['nullable', Rule::in(['select', 'radio'])],
            'label_en' => ['required', 'string', 'max:100'],
            'label_ar' => [Rule::requiredIf($this->audience($request, $isSystem) === 'all'), 'nullable', 'string', 'max:100'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'options' => ['array', 'max:200'],
            'options.*.label_en' => ['nullable', 'string', 'max:100'],
            'options.*.label_ar' => ['nullable', 'string', 'max:100'],
            'new_options' => ['array', 'max:20'],
            'new_options.*.value' => ['nullable', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,39}$/'],
            'new_options.*.label_en' => ['nullable', 'string', 'max:100'],
            'new_options.*.label_ar' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Existing options keep their values (relabel and (de)activate only); new
     * rows with a value are appended.
     *
     * @param  list<array{value: string, label_en: string, label_ar: string, is_active: bool}>  $existing
     * @return list<array{value: string, label_en: string, label_ar: string, is_active: bool}>
     */
    private function options(Request $request, array $existing, bool $arabicNeeded = true): array
    {
        $posted = (array) $request->input('options', []);
        $result = [];
        foreach ($existing as $index => $option) {
            $row = (array) ($posted[$index] ?? []);
            $result[] = [
                'value' => $option['value'],
                'label_en' => trim((string) ($row['label_en'] ?? $option['label_en'])) ?: $option['label_en'],
                'label_ar' => trim((string) ($row['label_ar'] ?? $option['label_ar'])) ?: $option['label_ar'],
                'is_active' => isset($row['is_active']) && $row['is_active'] === '1',
            ];
        }

        $values = array_column($result, 'value');
        foreach ((array) $request->input('new_options', []) as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $labelEn = trim((string) ($row['label_en'] ?? ''));
            $labelAr = trim((string) ($row['label_ar'] ?? ''));
            if (in_array($value, $values, true)) {
                throw ValidationException::withMessages(['new_options' => "The option value \"{$value}\" already exists."]);
            }
            // Staff-only lists are shown in English only, so their Arabic labels are optional (M9 CO-1).
            if ($labelEn === '' || ($arabicNeeded && $labelAr === '')) {
                throw ValidationException::withMessages(['new_options' => $arabicNeeded
                    ? "Give the option \"{$value}\" an English and an Arabic label."
                    : "Give the option \"{$value}\" an English label."]);
            }
            $result[] = ['value' => $value, 'label_en' => $labelEn, 'label_ar' => $labelAr, 'is_active' => true];
            $values[] = $value;
        }

        return $result;
    }

    private function activeCustomCount(): int
    {
        return FieldDefinition::query()->where('is_system', false)->where('is_active', true)->count();
    }
}
