<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * A value of a product variation from the ERP (Red, XL).
 */
class VariantTemplateItem extends Model
{
    use HasTranslations, HasUlids;

    protected $guarded = [];

    /** @var list<string> */
    public $translatable = ['name'];

    public function variantTemplate(): BelongsTo
    {
        return $this->belongsTo(VariantTemplate::class);
    }
}
