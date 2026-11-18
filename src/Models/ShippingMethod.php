<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;

class ShippingMethod extends Model
{
    use HasCachedImages, HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * The ERP sends the logo as a URL in `logo_link`; `logo_url` is its older storage path.
     *
     * @return array<string, string>
     */
    public static function cachedImages(): array
    {
        return ['logo_link' => 'logo_path'];
    }

    protected function displayImage(): Attribute
    {
        return Attribute::get(fn (): ?string => self::imageSource($this->logo_path, $this->logo_link ?: $this->logo_url));
    }

    public function scopeActive($query)
    {
        return $query->where('shipping_methods.status', 1);
    }
}
