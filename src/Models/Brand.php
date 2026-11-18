<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;
use Spatie\Translatable\HasTranslations;

class Brand extends Model
{
    use HasCachedImages, HasFactory, HasTranslations, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $translatable = [
        'name',
        'description',
        'slug',
        'title',
        'meta_description',
        'meta_keyword',
    ];

    public function scopeActive($query)
    {
        return $query->where('brands.status', 1);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
