<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A VAT rate pulled from the ERP: `type` {@see self::EXCLUSIVE} is added on top of the price,
 * {@see self::INCLUSIVE} is included in it.
 */
class Vat extends Model
{
    use HasUlids, SoftDeletes;

    public const EXCLUSIVE = 1;

    public const INCLUSIVE = 2;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'type' => 'integer',
        'percentage' => 'float',
    ];
}
