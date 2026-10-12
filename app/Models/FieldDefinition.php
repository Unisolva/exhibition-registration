<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A form field (work order §4 field_definitions): the five system fields and
 * up to 15 active custom fields.
 *
 * @property int $id
 * @property string $key
 * @property bool $is_system
 * @property string $type text|select|region|phone|email
 * @property string $label_en
 * @property string $label_ar
 * @property array<int, array{value: string, label_en: string, label_ar: string, is_active: bool}>|null $options
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 * @property bool $send_to_erp
 */
class FieldDefinition extends Model
{
    public const MAX_ACTIVE_CUSTOM = 15;

    /** System field keys and the visitors column each one fills. */
    public const SYSTEM_KEYS = ['name', 'phone', 'email', 'occupation', 'governorate'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'send_to_erp' => 'boolean',
        ];
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    public static function activeInOrder(): Collection
    {
        return static::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function label(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return $locale === 'ar' ? ($this->label_ar ?: $this->label_en) : ($this->label_en ?: $this->label_ar);
    }

    /** Name and phone are always required. */
    public function required(): bool
    {
        return in_array($this->key, ['name', 'phone'], true) || $this->is_required;
    }

    /**
     * @return list<array{value: string, label_en: string, label_ar: string, is_active: bool}>
     */
    public function activeOptions(): array
    {
        return array_values(array_filter($this->options ?? [], fn (array $option) => (bool) ($option['is_active'] ?? true)));
    }

    /** A select with no options is a free-text field (work order §4, occupation). */
    public function isFreeTextSelect(): bool
    {
        return $this->type === 'select' && $this->activeOptions() === [];
    }

    public function optionLabel(?string $value, ?string $locale = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $locale ??= app()->getLocale();

        foreach ($this->options ?? [] as $option) {
            if ($option['value'] === $value) {
                return $locale === 'ar' ? ($option['label_ar'] ?: $option['label_en']) : ($option['label_en'] ?: $option['label_ar']);
            }
        }

        return $value;
    }
}
