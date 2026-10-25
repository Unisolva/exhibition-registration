<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Erp;

use App\Models\Exhibition;
use App\Models\FieldDefinition;
use App\Models\Region;
use App\Models\Visitor;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * The lead payload for Odoo 18 (work order §8 "Payload"), built server-side
 * from the stored visitor and current settings, never from the request.
 *
 * Keys starting with "_" are resolved or removed by the drain before sending:
 * _country_code and _region_erp_code become country_id and state_id.
 */
class PayloadBuilder
{
    /** @var Collection<int, FieldDefinition>|null */
    private ?Collection $fields = null;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Keys of the fields that will be sent (shown before a push, stored on the batch).
     *
     * @return list<string>
     */
    public function fieldKeys(): array
    {
        return $this->sentFields()->pluck('key')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Visitor $visitor, string $extRef): array
    {
        $exhibition = Exhibition::query()->findOrFail($visitor->exhibition_id);
        $sent = $this->sentFields()->keyBy('key');
        $timezone = (string) $this->settings->get('display_timezone');

        $payload = [
            'ext_ref' => $extRef,
            'name' => strtr((string) $this->settings->get('lead_name_template') ?: '{exhibition}: {visitor_name}', [
                '{exhibition}' => $exhibition->name,
                '{visitor_name}' => $visitor->name,
            ]),
            'type' => 'lead',
            'user_id' => false,
            'contact_name' => $visitor->name,
            'phone' => $visitor->phone_valid && $visitor->phone_e164 ? $visitor->phone_e164 : $visitor->phone_raw,
        ];

        if ($visitor->email !== null && $sent->has('email')) {
            $payload['email_from'] = $visitor->email;
        }
        if ($visitor->occupation !== null && $sent->has('occupation')) {
            $payload['function'] = $sent['occupation']->choiceText($visitor, 'en');
        }

        $regionField = $sent->first(fn (FieldDefinition $field) => $field->type === 'region');
        if ($visitor->region_code !== null && $regionField !== null) {
            $country = (string) $this->settings->get('default_country');
            $region = Region::query()->where('country_code', $country)->where('code', $visitor->region_code)->first();
            // §16.4: country and state only when the visitor has a region.
            $payload['_country_code'] = $country;
            if ($region !== null) {
                $payload['_region_erp_code'] = $region->erp_code;
            }
        }

        foreach (['odoo_campaign_id' => 'campaign_id', 'odoo_source_id' => 'source_id'] as $setting => $field) {
            $id = (int) $this->settings->get($setting);
            if ($id > 0) {
                $payload[$field] = $id;
            }
        }
        $tags = array_values(array_filter(array_map('intval', (array) $this->settings->get('odoo_tag_ids')), fn ($id) => $id > 0));
        if ($tags !== []) {
            $payload['tag_ids'] = $tags;
        }

        $lines = [
            'Ref: '.$extRef,
            'Exhibition: '.$exhibition->name.' ('.$this->dates($exhibition).')',
            'Source: '.($visitor->source === 'public' ? 'QR form' : 'Staff entry'),
            'Registered: '.$visitor->created_at->timezone($timezone)->format('Y-m-d H:i').' ('.$timezone.')',
        ];
        foreach ($sent as $field) {
            $value = $this->displayValue($field, $visitor);
            if ($value !== null && $value !== '') {
                $lines[] = $field->label_en.': '.$value;
            }
        }
        $payload['description'] = '<p>'.implode('<br>', array_map(
            fn (string $line) => htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $lines,
        )).'</p>';

        return $payload;
    }

    /**
     * Active fields marked send_to_erp; name and phone always go.
     *
     * @return Collection<int, FieldDefinition>
     */
    private function sentFields(): Collection
    {
        return $this->fields ??= FieldDefinition::activeInOrder()
            ->filter(fn (FieldDefinition $field) => $field->send_to_erp || in_array($field->key, ['name', 'phone'], true))
            ->values();
    }

    private function displayValue(FieldDefinition $field, Visitor $visitor): ?string
    {
        return match (true) {
            $field->key === 'name' => $visitor->name,
            $field->key === 'phone' => $visitor->phone_valid && $visitor->phone_e164 ? $visitor->phone_e164 : $visitor->phone_raw,
            $field->key === 'email' => $visitor->email,
            $field->key === 'occupation', $field->type === 'select' => $field->choiceText($visitor, 'en'),
            $field->type === 'region' => $visitor->region_label,
            default => $visitor->extra[$field->key] ?? null,
        };
    }

    private function dates(Exhibition $exhibition): string
    {
        $start = $exhibition->starts_on->format('Y-m-d');

        return $exhibition->ends_on && ! $exhibition->ends_on->equalTo($exhibition->starts_on)
            ? $start.' to '.$exhibition->ends_on->format('Y-m-d')
            : $start;
    }
}
