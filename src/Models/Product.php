<?php

namespace Rishadblack\SyllooPosCore\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;
use Rishadblack\SyllooPosCore\Services\ProductSearchIndex;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasCachedImages, HasFactory, HasTranslations, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    public $translatable = [
        'name',
        'short_description',
        'description',
        'slug',
        'title',
        'meta_description',
        'meta_keyword',
    ];

    /**
     * A product saved here (not by a pull, which rebuilds in bulk) keeps its search text and barcodes (audit M4).
     */
    protected static function booted(): void
    {
        static::saved(fn (self $product) => app(ProductSearchIndex::class)->rebuild([(string) $product->id]));
    }

    protected function casts(): array
    {
        return [
            'category_ids' => 'array',
            'barcodes' => 'array',
            // The other units it sells in (erp: ProductPosData `units`), null from an older office (UnitConversion).
            'units' => 'array',
            'variants' => 'array',
            'variant_items' => 'array',
            'images' => 'array',
            'image_paths' => 'array',
            'kit_components' => 'array',
            'sale_discount_start' => 'datetime',
            'sale_discount_end' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function cachedImageLists(): array
    {
        return ['images' => 'image_paths'];
    }

    /**
     * The gallery to show: the local copy of each image, else its ERP URL.
     *
     * @return Attribute<list<string>, never>
     */
    protected function displayImages(): Attribute
    {
        return Attribute::get(function (): array {
            $paths = (array) $this->image_paths;

            return collect((array) $this->images)
                ->filter(fn ($url) => is_string($url) && $url !== '')
                ->values()
                ->map(fn (string $url, int $index) => self::imageSource($paths[$index] ?? null, $url))
                ->all();
        });
    }

    /**
     * The product a scanned code belongs to: its product code first, then any of its barcodes (matched exactly;
     * the LIKE on the stored JSON only narrows the rows to check).
     */
    public static function findByScanCode(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        // The code, else a barcode.
        return static::active()->where('products.code', $code)->first() ?? static::findByBarcode($code);
    }

    /**
     * The product a scanner read (a typing scanner or the camera): any of its barcodes first, then its product code, so
     * a printed barcode wins over a product code that happens to look the same. A variant has its own barcodes and is
     * its own product (`parent_id`), so its barcode finds the variant itself. From the Android till (SaleDesk).
     */
    public static function findByScannedBarcode(string $code): ?self
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        return static::findByBarcode($code) ?? static::active()->where('products.code', $code)->first();
    }

    protected static function findByBarcode(string $code): ?self
    {
        // A barcode by its index (audit M4); a till not indexed yet looks through the barcodes.
        return static::active()->whereIn('products.id', DB::table('product_barcodes')->where('code', $code)->select('product_id'))->first()
            ?? static::active()
                ->whereNull('products.search_text')
                ->where('products.barcodes', 'like', '%'.json_encode($code, JSON_UNESCAPED_UNICODE).'%')
                ->get()
                ->first(fn (self $product) => in_array($code, (array) $product->barcodes, true));
    }

    public function scopeActive($query)
    {
        return $query->where('products.status', 1);
    }

    /**
     * Sold unit by unit with one serial number each (the ERP's "Serialize", unless the product keeps no stock).
     */
    public function isSerialTracked(): bool
    {
        return (bool) $this->is_serialize && ! $this->is_inventory_disable;
    }

    /**
     * Sold only in its own unit: serial-numbered and batch items never change unit (the ERP's rule).
     */
    public function sellsInOwnUnitOnly(): bool
    {
        return $this->isSerialTracked() || (bool) $this->is_batch;
    }

    /**
     * Stock is counted for it at the office (not a service or an item without inventory).
     */
    public function tracksStock(): bool
    {
        return ! $this->is_inventory_disable;
    }

    /**
     * A kit: made of parts ({@see self::kitParts()}), the office takes ready kits first, then their parts.
     */
    public function isKit(): bool
    {
        return (bool) $this->is_kit && $this->kitParts() !== [];
    }

    /**
     * The parts one kit holds, as the ERP sends them.
     *
     * @return list<array{product_id: string, quantity: float}>
     */
    public function kitParts(): array
    {
        return collect((array) $this->kit_components)
            ->filter(fn ($part) => is_array($part) && filled($part['product_id'] ?? null) && (float) ($part['quantity'] ?? 0) > 0)
            ->map(fn (array $part) => ['product_id' => (string) $part['product_id'], 'quantity' => (float) $part['quantity']])
            ->values()
            ->all();
    }

    /**
     * The day the warranty of a unit sold on the given day ends, counted like the ERP (warranty_type 1 days,
     * 2 months, 3 years, from the start of the sale day), or null without a warranty.
     */
    public function warrantyUntil(CarbonInterface $soldAt): ?Carbon
    {
        $amount = (int) floor((float) $this->warranty_value);

        if ($amount <= 0) {
            return null;
        }

        $start = Carbon::parse($soldAt)->startOfDay();

        return match ((int) $this->warranty_type) {
            1 => $start->addDays($amount),
            3 => $start->addYearsNoOverflow($amount),
            default => $start->addMonthsNoOverflow($amount),
        };
    }

    /**
     * The warranty in words ("12 month(s)"), or '' without one.
     */
    public function warrantyLabel(): string
    {
        $amount = (int) floor((float) $this->warranty_value);

        if ($amount <= 0) {
            return '';
        }

        return $amount.' '.match ((int) $this->warranty_type) {
            1 => 'day(s)',
            3 => 'year(s)',
            default => 'month(s)',
        };
    }

    public function scopeFeatured($query)
    {
        return $query->whereNotNull('products.is_featured');
    }

    public function scopeTopRated($query)
    {
        return $query->whereNotNull('products.is_top_rated');
    }

    public function scopeTopSales($query)
    {
        return $query->whereNotNull('products.is_top_sales');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockLevel(): HasOne
    {
        return $this->hasOne(StockLevel::class, 'product_id');
    }

    public function productPricing(): HasMany
    {
        return $this->hasMany(ProductPricing::class);
    }

    public function parent(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
