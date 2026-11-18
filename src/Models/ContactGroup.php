<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class ContactGroup extends Model
{
    use HasFactory, HasTranslations, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $translatable = [
        'name',
    ];

    public static function setAdditionalConditionOnSerial($query, $model)
    {
        return $query->where('contact_groups.type', $model->getAttribute('type'));
    }

    public function scopeActive($query)
    {
        return $query->where('contact_groups.status', 1);
    }

    public function scopeSupplierGroup($query)
    {
        return $query->where('contact_groups.type', 2);
    }

    public function scopeCustomerGroup($query)
    {
        return $query->where('contact_groups.type', 1);
    }

    /**
     * The group(s) the ERP marks as default; is_default may be synced as 0 for the others.
     */
    public function scopeDefault($query)
    {
        return $query->where('contact_groups.is_default', 1);
    }
}
