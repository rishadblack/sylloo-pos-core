<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An add-on group of a restaurant's dishes, pulled from the ERP (erp: ProductAddonGroup, docs/restaurant.md, phase 7):
 * its choices (`items`: a product with its own price, or a no-charge `label`), how many the guest picks and the
 * products and categories it applies to. Nothing is typed in here.
 */
class AddonGroup extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'product_ids' => 'array',
            'category_ids' => 'array',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('addon_groups.status', 1)->orderBy('addon_groups.sort')->orderBy('addon_groups.name');
    }

    /**
     * The active groups offered with a product: named for it, or for one of its categories. Groups are few, so they
     * are read once and matched here.
     *
     * @return Collection<int, self>
     */
    public static function forProduct(Product $product): Collection
    {
        $categories = array_map('strval', (array) ($product->category_ids ?? []));
        $ids = array_filter([(string) $product->id, filled($product->parent_id) ? (string) $product->parent_id : null]);

        return static::query()->active()->get()->filter(fn (self $group) => array_intersect($ids, array_map('strval', (array) $group->product_ids)) !== []
            || array_intersect($categories, array_map('strval', (array) $group->category_ids)) !== [])->values();
    }

    /**
     * The choices of the group: a product id with a label, or a note.
     *
     * @return list<array{id: string, product_id: ?string, label: ?string}>
     */
    public function choices(): array
    {
        return collect((array) $this->items)
            ->filter(fn ($item) => is_array($item) && (filled($item['product_id'] ?? null) || filled($item['label'] ?? null)))
            ->map(fn (array $item) => ['id' => (string) ($item['id'] ?? md5(json_encode($item))), 'product_id' => filled($item['product_id'] ?? null) ? (string) $item['product_id'] : null, 'label' => filled($item['label'] ?? null) ? (string) $item['label'] : null])
            ->values()
            ->all();
    }
}
