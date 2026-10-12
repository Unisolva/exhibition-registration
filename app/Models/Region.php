<?php

// Copyright (c) 2026 Unisolva for Information Technology and App Development L.L.C. All rights reserved. See LICENSE.

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $country_code
 * @property string $code
 * @property string $name_en
 * @property string $name_ar
 * @property string $erp_code
 */
class Region extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    /**
     * @return Collection<int, Region>
     */
    public static function forCountry(string $country): Collection
    {
        return static::query()->where('country_code', $country)->orderBy('sort_order')->orderBy('code')->get();
    }

    public function name(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }
}
