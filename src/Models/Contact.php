<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Contact extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public static function setAdditionalConditionOnSerial($query, $model)
    {
        return $query->where('contacts.type', $model->getAttribute('type'));
    }

    public function scopeActive($query)
    {
        return $query->where('contacts.status', 1);
    }

    public function scopewalkIn($query)
    {
        return $query->whereNotNull('contacts.is_walk_in');
    }

    public function scopeSupplier($query)
    {
        return $query->where('contacts.type', 2);
    }

    public function scopeCustomer($query)
    {
        return $query->where('contacts.type', 1);
    }

    public function scopeIsCurrent($builder)
    {
        return $builder->whereUserId(auth()->id());
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public function deliveryCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'delivery_country_id');
    }

    public function deliveryState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'delivery_state_id');
    }

    public function generateCode()
    {
        do {
            $code = $this->generateOfflineContactCode();
        } while (self::where('code', $code)->exists());

        return $code;
    }

    protected function generateOfflineContactCode()
    {
        return 'CUS'.strtoupper(substr(Str::uuid()->toString(), 0, 8)); // 10 chars total
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'contact_id');
    }
}
