<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Registration;

/**
 * The normalised form of one submission (work order §5), with its errors.
 * Values are always filled as far as possible, so a replay can be compared by
 * hash even when the payload would not validate.
 */
final class Submission
{
    /**
     * @param  array<string, string|null>  $extra  custom field values keyed by field key
     * @param  array<string, string>  $errors  message per field key
     * @param  array<string, string>  $old  values as typed, to refill the form
     */
    public function __construct(
        public readonly string $name,
        public readonly string $phoneRaw,
        public readonly ?string $phoneE164,
        public readonly string $phoneKey,
        public readonly bool $phoneValid,
        public readonly ?string $email,
        public readonly ?string $occupation,
        public readonly ?string $regionCode,
        public readonly ?string $regionLabel,
        public readonly array $extra,
        public readonly array $errors,
        public readonly array $old,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * SHA-256 of the canonical JSON of the normalised values plus the
     * exhibition ID, keys sorted (§5 step 8). Never changes on later edits.
     */
    public function hash(int $exhibitionId): string
    {
        $extra = $this->extra;
        ksort($extra);

        $data = [
            'email' => $this->email,
            'exhibition_id' => $exhibitionId,
            'extra' => $extra,
            'name' => $this->name,
            'occupation' => $this->occupation,
            'phone_key' => $this->phoneKey,
            'region_code' => $this->regionCode,
        ];
        ksort($data);

        return hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Columns for the visitors table.
     *
     * @return array<string, mixed>
     */
    public function visitorAttributes(): array
    {
        return [
            'name' => $this->name,
            'phone_raw' => $this->phoneRaw,
            'phone_e164' => $this->phoneE164,
            'phone_key' => $this->phoneKey,
            'phone_valid' => $this->phoneValid,
            'email' => $this->email,
            'occupation' => $this->occupation,
            'region_code' => $this->regionCode,
            'region_label' => $this->regionLabel,
            'extra' => $this->extra,
        ];
    }
}
