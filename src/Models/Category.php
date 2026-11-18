<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;
use Spatie\Translatable\HasTranslations;

class Category extends Model
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
        return $query->where('categories.status', 1);
    }

    public function scopeFeatured($query)
    {
        return $query->whereNotNull('categories.is_featured');
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('categories.parent_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
