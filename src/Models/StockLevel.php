<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's stock in the till's warehouse as the ERP last sent it, in the item's own unit: on hand, held for
 * confirmed orders (`reserved`) and `available` (on hand less held). Keyed by the item's id.
 */
class StockLevel extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'on_hand' => 'float',
        'reserved' => 'float',
        'available' => 'float',
        'updated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
