<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = [
        'tenant_id',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'contact_summery_updated_at' => 'datetime',
        'summary_snap' => 'array',
        'store_credit' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('transactions.status', 1);
    }

    public function scopeDefault($query)
    {
        return $query->whereNotNull('transactions.is_default');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeCredit($query)
    {
        return $query->where('flow', 1);
    }

    public function scopeDebit($query)
    {
        return $query->where('flow', 2);
    }
}
