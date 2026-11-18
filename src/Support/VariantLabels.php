<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\VariantTemplateItem;

/**
 * What tells the variants of a product apart ("Colour: Red · Size: M"), from the values a variant names by id
 * (`variant_items`) and their names pulled from the ERP. Without the names (an older ERP) the variant's code is used.
 */
class VariantLabels
{
    /** @var array<string, array{kind: string, value: string, sort: int}>|null */
    protected ?array $items = null;

    public function for(Product $variant): string
    {
        $parts = collect((array) $variant->variant_items)
            ->map(fn ($id) => $this->items()[(string) $id] ?? null)
            ->filter()
            ->map(fn (array $item) => trim(($item['kind'] !== '' ? $item['kind'].': ' : '').$item['value']))
            ->all();

        return $parts !== [] ? implode(' · ', $parts) : (string) $variant->code;
    }

    /**
     * @return array<string, array{kind: string, value: string, sort: int}>
     */
    protected function items(): array
    {
        return $this->items ??= VariantTemplateItem::query()->with('variantTemplate')->get()
            ->mapWithKeys(fn (VariantTemplateItem $item) => [(string) $item->id => [
                'kind' => (string) $item->variantTemplate?->name,
                'value' => (string) $item->name,
                'sort' => (int) $item->sort,
            ]])->all();
    }
}
