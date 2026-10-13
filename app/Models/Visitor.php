<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Database\Factories\VisitorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registration at one exhibition (work order §4 visitors). Rows are never
 * removed: trash hides them, permanent deletion blanks their personal fields.
 *
 * @property int $id
 * @property int $exhibition_id
 * @property string $name
 * @property string $phone_raw
 * @property string|null $phone_e164
 * @property string $phone_key
 * @property bool $phone_valid
 * @property string|null $email
 * @property string|null $occupation
 * @property string|null $region_code
 * @property string|null $region_label
 * @property array<string, string|null>|null $extra
 * @property string $source public|staff
 * @property int|null $entered_by
 * @property string $submission_token
 * @property string $payload_hash
 * @property int|null $possible_duplicate_of
 */
class Visitor extends Model
{
    /** @use HasFactory<VisitorFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'phone_valid' => 'boolean',
            'extra' => 'array',
            'edited_at' => 'datetime',
            'trashed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /** Visitors that are neither in the trash nor permanently deleted. */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('trashed_at')->whereNull('deleted_at');
    }

    public function exhibition(): BelongsTo
    {
        return $this->belongsTo(Exhibition::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function trashedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trashed_by');
    }

    /**
     * The earliest current visitor at the same exhibition with the same phone
     * key, other than this one (work order §6 step 7).
     */
    public static function earliestDuplicateId(int $exhibitionId, string $phoneKey, ?int $exceptId = null): ?int
    {
        if ($phoneKey === '') {
            return null;
        }

        return static::query()->current()
            ->where('exhibition_id', $exhibitionId)
            ->where('phone_key', $phoneKey)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('created_at')->orderBy('id')
            ->value('id');
    }
}
