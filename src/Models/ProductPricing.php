<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Services\SalePricing;

/**
 * A product's sale price for one customer group, pulled from the ERP. The ERP charges a customer
 * of that group this price (see {@see SalePricing}).
 */
class ProductPricing extends Model
{
    use HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sale_discount_start' => 'datetime',
            'sale_discount_end' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
