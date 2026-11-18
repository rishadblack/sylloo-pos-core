<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * A kind of product variation from the ERP (Colour, Size).
 */
class VariantTemplate extends Model
{
    use HasTranslations, HasUlids;

    protected $guarded = [];

    /** @var list<string> */
    public $translatable = ['name'];
}
