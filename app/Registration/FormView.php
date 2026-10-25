<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Registration;

use App\Models\FieldDefinition;
use App\Models\Region;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * What a registration form shows, field by field, in one language. Shared by
 * the public form and staff entry so both render the same fields.
 */
class FormView
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * The public form passes $publicOnly: staff-only fields never appear there (M9 CO-2).
     *
     * @param  Collection<int, FieldDefinition>|null  $fields
     * @return list<array{key: string, label: string, required: bool, control: string, type: string, options: array<string, string>, maxlength: int, autocomplete: string|null, inputmode: string|null, dir: string|null}>
     */
    public function fields(string $locale, ?Collection $fields = null, bool $publicOnly = false): array
    {
        $fields ??= FieldDefinition::activeInOrder($publicOnly);
        $out = [];

        foreach ($fields as $field) {
            $options = [];
            $control = 'input';

            if ($field->type === 'region') {
                $control = 'select';
                foreach (Region::forCountry((string) $this->settings->get('default_country')) as $region) {
                    $options[$region->code] = $region->name($locale);
                }
            } elseif ($field->type === 'select' && ! $field->isFreeTextSelect()) {
                $control = $field->isRadio() ? 'radio' : 'select';
                foreach ($field->activeOptions() as $option) {
                    $options[$option['value']] = $locale === 'ar' ? ($option['label_ar'] ?: $option['label_en']) : ($option['label_en'] ?: $option['label_ar']);
                }
            }

            [$type, $maxlength, $autocomplete, $inputmode, $dir] = match ($field->key) {
                'name' => ['text', Normalizer::MAX_NAME, 'name', null, null],
                'phone' => ['tel', Normalizer::MAX_PHONE, 'tel', 'tel', 'ltr'],
                'email' => ['email', Normalizer::MAX_EMAIL, 'email', 'email', 'ltr'],
                'occupation' => ['text', Normalizer::MAX_OCCUPATION, 'organization-title', null, null],
                default => ['text', Normalizer::MAX_CUSTOM_TEXT, 'off', null, null],
            };

            $out[] = [
                // M10 CO-5: the choice that shows "Please specify", or null.
                'details' => in_array($control, ['select', 'radio'], true) ? $field->detailsOption() : null,
                'key' => $field->key,
                'label' => $field->label($locale),
                'required' => $field->required(),
                'control' => $control,
                'type' => $type,
                'options' => $options,
                'maxlength' => $maxlength,
                'autocomplete' => $autocomplete,
                'inputmode' => $inputmode,
                'dir' => $dir,
            ];
        }

        return $out;
    }
}
