<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Services\UnitConversion;
use Spatie\Translatable\HasTranslations;

/**
 * A unit pulled from the ERP. A unit with a `base_unit_id` holds `factor` of that unit (a box: 12 pieces); one without
 * is a base unit. Two units convert when they share the same base ({@see UnitConversion}).
 */
class Unit extends Model
{
    use HasTranslations, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $translatable = ['name'];

    protected $casts = [
        'factor' => 'float',
        'status' => 'integer',
    ];
}
