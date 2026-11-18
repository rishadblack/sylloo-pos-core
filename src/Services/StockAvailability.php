<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\StockLevel;

/**
 * What the till can still sell of an item, from the stock levels the ERP sent ({@see StockLevel}, in the item's own
 * unit) less what this till sold that has not reached the office yet. A kit counts its ready kits plus what its
 * scarcest part allows. Only a guide: the till always sells, and the office checks every sale it receives.
 */
class StockAvailability
{
    private ?bool $hasLevels = null;

    /**
     * Order types (orders.type) that move stock on this till: a sale takes units, a return puts them back.
     */
    public const ORDER_TYPE_SALE = 1;

    public const ORDER_TYPE_RETURN = 3;

    public function __construct(private UnitConversion $units) {}

    /**
     * By product id, for items whose stock the office counts (nothing until the first stock levels arrive):
     * `available` what can still be sold, `ready` the item's own stock, `buildable` for a kit what its parts allow,
     * `low` at or below the item's low stock alert, `sells_without_stock` may be sold when there is none.
     *
     * @param  iterable<int, Product>  $products
     * @return array<string, array{available: float, ready: float, buildable: ?float, is_kit: bool, low: bool, sells_without_stock: bool}>
     */
    public function forProducts(iterable $products): array
    {
        $products = collect($products)->filter(fn ($product) => $product instanceof Product && $product->tracksStock())->keyBy(fn (Product $product) => (string) $product->id);

        if ($products->isEmpty() || ! $this->hasLevels()) {
            return [];
        }

        $partIds = $products->filter(fn (Product $product) => $product->isKit())
            ->flatMap(fn (Product $product) => array_column($product->kitParts(), 'product_id'))
            ->all();
        $ids = array_values(array_unique([...$products->keys()->all(), ...$partIds]));

        $levels = StockLevel::query()->whereIn('stock_levels.product_id', $ids)->pluck('available', 'product_id')->map(fn ($value) => (float) $value);
        $pending = $this->pendingQuantities($ids);
        $left = fn (string $id): float => (float) ($levels[$id] ?? 0) - (float) ($pending[$id] ?? 0);

        $result = [];

        foreach ($products as $id => $product) {
            $own = $left($id);
            $buildable = null;

            if ($product->isKit()) {
                $buildable = (float) collect($product->kitParts())
                    ->map(fn (array $part) => max(0.0, floor(round($left($part['product_id']) / $part['quantity'], 6))))
                    ->min();
            }

            $available = round($own + ($buildable ?? 0.0), 6);
            $alert = (float) $product->low_stock_alert;

            $result[$id] = [
                'available' => $available,
                'ready' => round(max(0.0, $own), 6),
                'buildable' => $buildable,
                'is_kit' => $product->isKit(),
                'low' => $alert > 0 && $available <= $alert,
                'sells_without_stock' => (bool) $product->is_out_of_stock_sale,
            ];
        }

        return $result;
    }

    /**
     * One item's availability, or null when its stock is not counted (or no stock levels arrived yet).
     *
     * @return array{available: float, ready: float, buildable: ?float, is_kit: bool, low: bool, sells_without_stock: bool}|null
     */
    public function forProduct(Product $product): ?array
    {
        return $this->forProducts([$product])[(string) $product->id] ?? null;
    }

    /**
     * A stock quantity as the till shows it: "12", "2.5", "-3".
     */
    public static function quantityText(float $quantity): string
    {
        $text = rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /**
     * True once the ERP has sent stock levels to this till.
     */
    public function hasLevels(): bool
    {
        return $this->hasLevels ??= StockLevel::query()->exists();
    }

    /**
     * What completed sales not yet received by the office take of each item, in the item's own unit, and what open table
     * bills already sent to the kitchen. A return not yet received puts its units back, so it counts against the sales.
     *
     * @param  list<string>  $productIds
     * @return array<string, float>
     */
    private function pendingQuantities(array $productIds): array
    {
        $lines = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->whereNull('orders.last_sync_at')
            // A restaurant's open bills count what the kitchen already has (erp: docs/restaurant.md, phase 8).
            ->where(fn ($query) => $query->where('orders.status', '!=', Order::STATUS_DRAFT)
                ->orWhere(fn ($bill) => $bill->where('orders.status', Order::STATUS_DRAFT)->whereNotNull('orders.dining_table_id')->where('order_items.kitchen_sent_quantity', '>', 0)))
            ->where(fn ($query) => $query->whereNull('orders.type')->orWhereIn('orders.type', [self::ORDER_TYPE_SALE, self::ORDER_TYPE_RETURN]))
            ->whereIn('order_items.product_id', $productIds)
            ->get(['order_items.product_id', 'order_items.unit_id', 'order_items.quantity', 'order_items.kitchen_sent_quantity', 'orders.type as order_type', 'orders.status as order_status']);

        if ($lines->isEmpty()) {
            return [];
        }

        $products = $this->productsWithOtherUnits($lines);
        $pending = [];

        foreach ($lines as $line) {
            $product = $products->get((string) $line->product_id);
            $ratio = $product && filled($line->unit_id) ? ($this->units->ratio($product, (string) $line->unit_id) ?? 1.0) : 1.0;
            $sign = (int) $line->order_type === self::ORDER_TYPE_RETURN ? -1 : 1;
            $quantity = (int) $line->order_status === Order::STATUS_DRAFT ? (float) $line->kitchen_sent_quantity : (float) $line->quantity;
            $pending[(string) $line->product_id] = ($pending[(string) $line->product_id] ?? 0.0) + $sign * abs($quantity) * $ratio;
        }

        return $pending;
    }

    /**
     * The products of the lines sold in a unit, to convert their quantity.
     *
     * @param  Collection<int, OrderItem>  $lines
     * @return Collection<string, Product>
     */
    private function productsWithOtherUnits(Collection $lines): Collection
    {
        $ids = $lines->filter(fn (OrderItem $line) => filled($line->unit_id))->pluck('product_id')->unique()->values()->all();

        return $ids === [] ? collect() : Product::withTrashed()->whereIn('products.id', $ids)->get()->keyBy(fn (Product $product) => (string) $product->id);
    }
}
