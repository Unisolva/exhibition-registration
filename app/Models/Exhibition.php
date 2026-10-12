<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use App\Support\Crockford;
use Carbon\CarbonImmutable;
use Database\Factories\ExhibitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property CarbonImmutable|Carbon $starts_on
 * @property CarbonImmutable|Carbon|null $ends_on
 * @property string $public_token
 * @property bool $is_open
 */
class Exhibition extends Model
{
    /** @use HasFactory<ExhibitionFactory> */
    use HasFactory;

    public const TOKEN_LENGTH = 10;

    protected $fillable = ['name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_open' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // The token is created once and never changes, so printed QR codes keep working.
        static::creating(function (Exhibition $exhibition) {
            $exhibition->public_token ??= static::uniqueToken();
            $exhibition->is_open ??= true;
        });
    }

    public static function uniqueToken(): string
    {
        do {
            $token = Crockford::random(self::TOKEN_LENGTH);
        } while (static::query()->where('public_token', $token)->exists());

        return $token;
    }

    public static function findByPublicToken(string $token): ?self
    {
        $token = Crockford::normalize($token);
        if (preg_match('/^['.Crockford::ALPHABET.']{'.self::TOKEN_LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        return static::query()->where('public_token', $token)->first();
    }

    public function publicUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/r/'.$this->public_token;
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }
}
