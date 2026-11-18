<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\Unit;

/**
 * Units a product sells in, worked out like the ERP (its Product UnitConversion and LineUnit):
 *
 * - The office sends each product's own units (`products.units`, its Units tab: a box of 6, with its own sale price
 *   when set). Only those convert: a unit the product has no row for is not offered.
 * - From an older office (no `units`), the unit list's conversions are used: a unit with a base unit holds `factor`
 *   of it (a dozen: 12 pieces), and two units convert when they share the same base.
 *
 * Quantities (stock, a discount's least quantity) always count in the product's own unit (`ratio()`); the price of one
 * unit is the item's price times `priceFactor()`, the own price over the item's price when the unit has one. The units
 * are read once per instance.
 */
class UnitConversion
{
    /** @var Collection<string, Unit>|null */
    private ?Collection $units = null;

    /**
     * How many of the product's own unit one `$unitId` is; null when the product does not sell in that unit or sells
     * only in its own unit. No unit, or the product's own unit, is 1.
     */
    public function ratio(Product $product, ?string $unitId): ?float
    {
        $ownUnitId = $product->unit_id ? (string) $product->unit_id : null;

        if (blank($unitId) || (string) $unitId === (string) $ownUnitId) {
            return 1.0;
        }

        if ($product->sellsInOwnUnitOnly()) {
            return null;
        }

        if (is_array($product->units)) {
            $row = $this->rowOf($product, (string) $unitId);

            return $row !== null && $this->isActive((string) $unitId) ? $row['factor'] : null;
        }

        $unit = $this->unit($unitId);
        $ownUnit = $this->unit($ownUnitId);

        if (! $unit || ! $ownUnit || (int) $unit->status !== 1 || $this->rootOf($unit) !== $this->rootOf($ownUnit)) {
            return null;
        }

        $ownFactor = $this->factorOf($ownUnit);

        return $ownFactor > 0 ? $this->factorOf($unit) / $ownFactor : null;
    }

    /**
     * What one unit's price is of the item's own unit price (discounts and VAT follow in proportion), as the ERP's
     * LineUnit::priceFactor(): the unit's own sale price over the item's sale price (before VAT) when the office set one,
     * else how many of the item's own unit it is. Null when the product does not sell in that unit.
     */
    public function priceFactor(Product $product, ?string $unitId, float $itemSalePrice): ?float
    {
        $ratio = $this->ratio($product, $unitId);

        if ($ratio === null || blank($unitId) || (string) $unitId === (string) $product->unit_id) {
            return $ratio;
        }

        $ownPrice = $this->rowOf($product, (string) $unitId)['sale_price'] ?? null;

        return $ownPrice !== null && $itemSalePrice > 0 ? $ownPrice / $itemSalePrice : $ratio;
    }

    /**
     * The units the cashier may sell the product in, its own unit first: none when it has only its own unit (or sells
     * only in it), so the till shows no unit choice.
     *
     * @return list<array{id: string, name: string, ratio: float}>
     */
    public function choicesFor(Product $product): array
    {
        $ownUnit = $this->unit($product->unit_id ? (string) $product->unit_id : null);

        if (! $ownUnit || $product->sellsInOwnUnitOnly()) {
            return [];
        }

        if (is_array($product->units)) {
            $choices = collect($this->rowsOf($product))
                ->filter(fn (array $row, string $unitId) => $this->isActive($unitId))
                ->map(fn (array $row, string $unitId) => ['id' => $unitId, 'name' => $this->nameOf($unitId), 'ratio' => $row['factor']])
                ->sortBy('ratio')
                ->values()
                ->prepend(['id' => (string) $ownUnit->id, 'name' => (string) $ownUnit->name, 'ratio' => 1.0])
                ->all();

            return count($choices) > 1 ? $choices : [];
        }

        $root = $this->rootOf($ownUnit);
        $ownFactor = $this->factorOf($ownUnit);

        if ($ownFactor <= 0) {
            return [];
        }

        $choices = $this->units()
            ->filter(fn (Unit $unit) => (string) $unit->id === (string) $ownUnit->id || ((int) $unit->status === 1 && $this->rootOf($unit) === $root && $this->factorOf($unit) > 0))
            ->sortBy(fn (Unit $unit) => [(string) $unit->id === (string) $ownUnit->id ? 0 : 1, $this->factorOf($unit)])
            ->map(fn (Unit $unit) => ['id' => (string) $unit->id, 'name' => (string) $unit->name, 'ratio' => round($this->factorOf($unit) / $ownFactor, 6)])
            ->values()
            ->all();

        return count($choices) > 1 ? $choices : [];
    }

    /**
     * The unit a new line starts in: the office's "Sell in" unit of the product when it still sells in it, else its own
     * unit.
     */
    public function defaultSaleUnitId(Product $product): ?string
    {
        $ownUnitId = $product->unit_id ? (string) $product->unit_id : null;

        if (! is_array($product->units) || $product->sellsInOwnUnitOnly()) {
            return $ownUnitId;
        }

        foreach ($this->rowsOf($product) as $unitId => $row) {
            if ($row['is_default_sale'] && $this->isActive($unitId)) {
                return $unitId;
            }
        }

        return $ownUnitId;
    }

    /**
     * The unit's name in the current language, or '' when it is not known here.
     */
    public function nameOf(?string $unitId): string
    {
        return (string) ($this->unit($unitId)?->name ?? '');
    }

    /**
     * The product's own unit rows by unit id (a positive factor only).
     *
     * @return array<string, array{factor: float, sale_price: ?float, is_default_sale: bool}>
     */
    private function rowsOf(Product $product): array
    {
        $rows = [];

        foreach ((array) $product->units as $row) {
            $unitId = is_array($row) ? (string) ($row['unit_id'] ?? '') : '';
            $factor = is_array($row) ? (float) ($row['factor'] ?? 0) : 0.0;

            if ($unitId === '' || $factor <= 0 || $unitId === (string) $product->unit_id) {
                continue;
            }

            $rows[$unitId] = [
                'factor' => round($factor, 6),
                'sale_price' => is_numeric($row['sale_price'] ?? null) ? (float) $row['sale_price'] : null,
                'is_default_sale' => (bool) ($row['is_default_sale'] ?? false),
            ];
        }

        return $rows;
    }

    /**
     * @return array{factor: float, sale_price: ?float, is_default_sale: bool}|null
     */
    private function rowOf(Product $product, string $unitId): ?array
    {
        return $this->rowsOf($product)[$unitId] ?? null;
    }

    /**
     * A unit sells while it is on this till and active (a unit not pulled yet also counts, by its row).
     */
    private function isActive(string $unitId): bool
    {
        $unit = $this->unit($unitId);

        return $unit === null || (int) $unit->status === 1;
    }

    private function unit(?string $id): ?Unit
    {
        return blank($id) ? null : $this->units()->get((string) $id);
    }

    private function rootOf(Unit $unit): string
    {
        return (string) ($unit->base_unit_id ?: $unit->id);
    }

    private function factorOf(Unit $unit): float
    {
        return $unit->base_unit_id ? max((float) $unit->factor, 0.0) : 1.0;
    }

    /**
     * @return Collection<string, Unit>
     */
    private function units(): Collection
    {
        return $this->units ??= Unit::query()->get()->keyBy(fn (Unit $unit) => (string) $unit->id);
    }
}
