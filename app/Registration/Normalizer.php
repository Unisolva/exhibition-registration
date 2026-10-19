<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Registration;

use App\Models\FieldDefinition;
use App\Models\Region;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Illuminate\Support\Collection;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalisation and validation shared by the public form and staff entry
 * (work order §5). Only active fields are read; anything else in the input is
 * ignored.
 */
class Normalizer
{
    public const MAX_NAME = 150;

    public const MAX_PHONE = 40;

    public const MAX_EMAIL = 254;

    public const MAX_OCCUPATION = 100;

    public const MAX_CUSTOM_TEXT = 255;

    public const MIN_PHONE_DIGITS = 7;

    /**
     * @param  array<string, mixed>  $input  raw request values keyed by field key
     * @param  Collection<int, FieldDefinition>  $fields  active fields
     */
    public function normalize(array $input, Collection $fields, string $country, string $locale): Submission
    {
        $errors = [];
        $old = [];
        $values = [
            'name' => '', 'phone_raw' => '', 'phone_e164' => null, 'phone_key' => '', 'phone_valid' => false,
            'email' => null, 'occupation' => null, 'region_code' => null, 'region_label' => null,
        ];
        $extra = [];

        foreach ($fields as $field) {
            $raw = $input[$field->key] ?? '';
            $typed = is_string($raw) ? $raw : '';
            $old[$field->key] = $typed;
            $label = $field->label($locale);

            $value = self::trim($typed);

            if ($value === '') {
                if ($field->required()) {
                    $errors[$field->key] = $field->isRadio()
                        ? __('public.error.choose_one', [], $locale)
                        : __('public.error.required', ['field' => $label], $locale);
                }
                if (! $field->is_system) {
                    $extra[$field->key] = null;
                }

                continue;
            }

            match (true) {
                $field->key === 'name' => $this->name($value, $label, $locale, $values, $errors),
                $field->key === 'phone' => $this->phone($value, $country, $label, $locale, $values, $errors),
                $field->key === 'email' => $this->email($value, $label, $locale, $values, $errors),
                $field->key === 'occupation' => $values['occupation'] = $this->choice($field, $value, self::MAX_OCCUPATION, $label, $locale, $errors),
                $field->type === 'region' => $this->region($field, $value, $country, $label, $locale, $values, $errors),
                default => $extra[$field->key] = $this->choice($field, self::digitsOnlyToAscii($value), self::MAX_CUSTOM_TEXT, $label, $locale, $errors),
            };
        }

        return new Submission(
            name: $values['name'],
            phoneRaw: $values['phone_raw'],
            phoneE164: $values['phone_e164'],
            phoneKey: $values['phone_key'],
            phoneValid: $values['phone_valid'],
            email: $values['email'],
            occupation: $values['occupation'],
            regionCode: $values['region_code'],
            regionLabel: $values['region_label'],
            extra: $extra,
            errors: $errors,
            old: $old,
        );
    }

    /** Eastern Arabic (U+0660..0669) and extended Arabic-Indic (U+06F0..06F9) digits to ASCII. */
    public static function asciiDigits(string $value): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            for ($d = 0; $d <= 9; $d++) {
                $map[mb_chr(0x0660 + $d)] = (string) $d;
                $map[mb_chr(0x06F0 + $d)] = (string) $d;
            }
        }

        return strtr($value, $map);
    }

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^[\s\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+|[\s\x{00A0}\x{200B}-\x{200F}\x{FEFF}]+$/u', '', $value);
    }

    /**
     * Cleaned phone digits as stored in phone_key when the number cannot be parsed.
     */
    public static function phoneKeyFor(string $input, string $country): string
    {
        $cleaned = self::cleanPhone($input);
        $util = PhoneNumberUtil::getInstance();
        try {
            return $util->format($util->parse($cleaned, $country), PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            return self::digitsKey($cleaned);
        }
    }

    private static function cleanPhone(string $value): string
    {
        $value = self::asciiDigits($value);
        $value = (string) preg_replace('/[\s\-.()\x{00A0}]+/u', '', $value);
        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }

        return $value;
    }

    private static function digitsKey(string $cleaned): string
    {
        $digits = (string) preg_replace('/\D+/', '', $cleaned);

        return (str_starts_with($cleaned, '+') ? '+' : '').$digits;
    }

    private static function digitsOnlyToAscii(string $value): string
    {
        return preg_match('/^[\p{Nd}\s]+$/u', $value) === 1 ? self::asciiDigits($value) : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function name(string $value, string $label, string $locale, array &$values, array &$errors): void
    {
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $values['name'] = $value;

        if (mb_strlen($value) > self::MAX_NAME) {
            $errors['name'] = __('public.error.too_long', ['field' => $label, 'max' => self::MAX_NAME], $locale);
        }
    }

    /**
     * Work order §5 step 3 and §16.5: a number that parses gets an E.164 key;
     * phone_e164 and phone_valid only when it is also valid. Rejected only when
     * fewer than 7 digits remain.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function phone(string $value, string $country, string $label, string $locale, array &$values, array &$errors): void
    {
        $values['phone_raw'] = mb_substr($value, 0, self::MAX_PHONE);
        $cleaned = self::cleanPhone($value);
        $digits = (string) preg_replace('/\D+/', '', $cleaned);
        $values['phone_key'] = mb_substr(self::digitsKey($cleaned), 0, 40);

        if (mb_strlen($value) > self::MAX_PHONE) {
            $errors['phone'] = __('public.error.too_long', ['field' => $label, 'max' => self::MAX_PHONE], $locale);

            return;
        }

        if (strlen($digits) < self::MIN_PHONE_DIGITS) {
            $errors['phone'] = __('public.error.phone_short', ['field' => $label], $locale);

            return;
        }

        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($cleaned, $country);
        } catch (NumberParseException) {
            return;
        }

        $e164 = $util->format($number, PhoneNumberFormat::E164);
        $values['phone_key'] = $e164;
        if ($util->isValidNumber($number)) {
            $values['phone_e164'] = $e164;
            $values['phone_valid'] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function email(string $value, string $label, string $locale, array &$values, array &$errors): void
    {
        $at = strrpos($value, '@');
        if ($at !== false) {
            $value = substr($value, 0, $at).'@'.mb_strtolower(substr($value, $at + 1));
        }
        $values['email'] = $value;

        if (mb_strlen($value) > self::MAX_EMAIL) {
            $errors['email'] = __('public.error.too_long', ['field' => $label, 'max' => self::MAX_EMAIL], $locale);
        } elseif (! (new EmailValidator)->isValid($value, new RFCValidation)) {
            $errors['email'] = __('public.error.email', ['field' => $label], $locale);
        }
    }

    /**
     * A text value, or for a select with options, one of its active option values.
     *
     * @param  array<string, string>  $errors
     */
    private function choice(FieldDefinition $field, string $value, int $max, string $label, string $locale, array &$errors): string
    {
        if ($field->type === 'select' && ! $field->isFreeTextSelect()) {
            $allowed = array_column($field->activeOptions(), 'value');
            if (! in_array($value, $allowed, true)) {
                $errors[$field->key] = __('public.error.option', ['field' => $label], $locale);
            }

            return $value;
        }

        if (mb_strlen($value) > $max) {
            $errors[$field->key] = __('public.error.too_long', ['field' => $label, 'max' => $max], $locale);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $errors
     */
    private function region(FieldDefinition $field, string $value, string $country, string $label, string $locale, array &$values, array &$errors): void
    {
        $region = Region::query()->where('country_code', $country)->where('code', $value)->first();
        $values['region_code'] = $region?->code ?? mb_substr($value, 0, 10);

        if ($region === null) {
            $errors[$field->key] = __('public.error.option', ['field' => $label], $locale);

            return;
        }

        // Snapshot in English, the staff language; the code is what the ERP receives.
        $values['region_label'] = $region->name_en;
    }
}
