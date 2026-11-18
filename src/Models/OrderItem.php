<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class OrderItem extends Model
{
    use HasFactory, HasTranslations, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $translatable = [
        'name',
    ];

    protected $casts = [
        'order_item_date' => 'datetime',
        'batch_tracking_updated_at' => 'datetime',
        'serial_tracking_updated_at' => 'datetime',
        'batch_tracking' => 'array',
        'serial_tracking' => 'array',
        'price_change' => 'array',
        'additionals' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('order_items.status', 1);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The unit the line was sold in.
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class)->withTrashed();
    }

    /**
     * The unit's name when the line was sold in another unit than the item's own (a box of 12), else ''.
     */
    public function otherUnitName(): string
    {
        if (blank($this->unit_id) || (string) $this->unit_id === (string) ($this->product?->unit_id ?? '')) {
            return '';
        }

        return (string) ($this->unit?->name ?? '');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
