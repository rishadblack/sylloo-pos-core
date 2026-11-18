<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\ProductPricing;
use Rishadblack\SyllooPosCore\Models\Vat;
use Throwable;

/**
 * What a product costs on this till, worked out like the ERP prices a POS sale (its PosOrderPricing):
 * the product's sale price, or its price for the customer's group, with its sale VAT rate.
 *
 * Dated discounts (owner decision 2026-11-10, erp: docs/README.md §8q) are priced offline too: the ERP sends the
 * discounted price (`net_sale_price`, `sale_vat_amount`) next to the price without it (`undiscounted_*`) and the
 * discount's amount, dates and least quantity. The discount applies only while its amount is above zero, now is within
 * its start and end (both included; an empty date is open) and the line's quantity reaches its least quantity; else
 * the price without it is charged. An older ERP that sends no price without the discount is priced as it always was.
 */
class SalePricing
{
    /**
     * By product id: `unit_with_vat` what one unit costs the customer now (the tile and the price check), `price` the
     * price as {@see OrderCalculator} takes it (without the tax for a rate added on top) and the VAT rate; `regular`
     * the price without the discount and `discount` the discounted price with when it applies (null without a dated
     * discount), so a cart line is priced for its own quantity ({@see self::forQuantity()}). A rate not pulled yet
     * counts as no VAT.
     *
     * @param  Collection<string, Product>  $products  keyed by id
     * @return array<string, array{unit_with_vat: float, price: float, vat_id: ?string, vat_type: ?int, vat_percentage: float, sale_price: float, regular: array{unit_with_vat: float, price: float}, discount: ?array{unit_with_vat: float, price: float, min_qty: float, starts: ?int, ends: ?int}}>
     */
    public function prices(Collection $products, ?string $contactGroupId): array
    {
        $vatIds = $products->pluck('sale_vat_id')->filter()->unique()->values()->all();
        $vats = $vatIds === [] ? collect() : Vat::query()->whereIn('vats.id', $vatIds)->get()->keyBy('id');

        $groupPrices = $contactGroupId && $products->isNotEmpty()
            ? ProductPricing::query()
                ->whereIn('product_pricings.product_id', $products->keys()->all())
                ->where('product_pricings.contact_group_id', $contactGroupId)
                ->get()
                ->keyBy('product_id')
            : collect();

        $prices = [];

        foreach ($products as $productId => $product) {
            $vat = filled($product->sale_vat_id) ? $vats->get($product->sale_vat_id) : null;
            $isExclusive = $vat !== null && (int) $vat->type === Vat::EXCLUSIVE;
            $groupPrice = $groupPrices->get($productId);
            $source = $groupPrice ?? $product;

            $withVat = (float) ($groupPrice->net_sale_price ?? $product->net_sale_price);
            $vatAmount = (float) ($groupPrice->sale_vat_amount ?? $product->sale_vat_amount);
            $priced = fn (float $unitWithVat, float $vatOfUnit) => [
                'unit_with_vat' => round($unitWithVat, 6),
                'price' => round($unitWithVat - ($isExclusive ? $vatOfUnit : 0), 6),
            ];

            // An older ERP sends only the discounted price: charged as it always was.
            if ($source->undiscounted_net_sale_price === null) {
                $regular = $priced($withVat, $vatAmount);
                $discount = null;
            } else {
                $regular = $priced((float) $source->undiscounted_net_sale_price, (float) ($source->undiscounted_sale_vat_amount ?? 0));
                $discount = (float) ($source->sale_discount_amount ?? 0) > 0
                    ? [
                        ...$priced($withVat, $vatAmount),
                        'min_qty' => max(0.0, (float) ($source->sale_discount_min_qty ?? 0)),
                        'starts' => self::timestamp($source, 'sale_discount_start'),
                        'ends' => self::timestamp($source, 'sale_discount_end'),
                    ]
                    : null;
            }

            $prices[$productId] = [
                ...self::forQuantity(['regular' => $regular, 'discount' => $discount], 1.0),
                'vat_id' => $vat?->id,
                'vat_type' => $vat ? (int) $vat->type : null,
                'vat_percentage' => $vat ? max(0.0, (float) $vat->percentage) : 0.0,
                // The item's sale price before VAT and discount: a unit's own price is compared with it (UnitConversion::priceFactor()).
                'sale_price' => (float) ($groupPrice->sale_price ?? $product->sale_price ?? 0),
                'regular' => $regular,
                'discount' => $discount,
            ];
        }

        return $prices;
    }

    /**
     * The unit price of a line of this quantity now: the discounted price while the discount runs (its dates, both
     * included) and the quantity reaches its least quantity, else the price without it. A price without `regular`
     * (kept from before) is taken as it is.
     *
     * @param  array<string, mixed>  $price  an entry of {@see self::prices()}
     * @return array{unit_with_vat: float, price: float}
     */
    public static function forQuantity(array $price, float $quantity, ?Carbon $now = null): array
    {
        if (! isset($price['regular'])) {
            return ['unit_with_vat' => (float) ($price['unit_with_vat'] ?? 0), 'price' => (float) ($price['price'] ?? 0)];
        }

        $discount = $price['discount'] ?? null;
        $at = ($now ?? now())->getTimestamp();

        $applies = is_array($discount)
            && ($discount['starts'] === null || $at >= $discount['starts'])
            && ($discount['ends'] === null || $at <= $discount['ends'])
            && $quantity + 0.000001 >= (float) $discount['min_qty'];

        $chosen = $applies ? $discount : $price['regular'];

        return ['unit_with_vat' => (float) $chosen['unit_with_vat'], 'price' => (float) $chosen['price']];
    }

    /**
     * A discount date as a Unix time, or null when open or not a date. Kept as a number so the cart's price cache can
     * hold it and the date is checked when the line is priced, not when it was read.
     */
    protected static function timestamp(Model $source, string $column): ?int
    {
        try {
            $value = $source->getAttribute($column);

            return $value === null || $value === '' ? null : Carbon::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
