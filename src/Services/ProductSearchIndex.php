<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Rishadblack\SyllooPosCore\Models\Product;

/**
 * The till's product search index (audit M4): `products.search_text` holds the name in every language, the code and
 * every barcode, lower-cased, so the grid searches one column; `product_barcodes` maps each barcode to its product, so
 * a scan is one indexed lookup. Rebuilt for the products a pull brings.
 */
class ProductSearchIndex
{
    /**
     * @param  list<string>|null  $productIds  null: every product
     */
    public function rebuild(?array $productIds = null): void
    {
        $query = Product::withTrashed()->select(['id', 'name', 'code', 'barcodes']);

        if ($productIds !== null) {
            if ($productIds === []) {
                return;
            }

            $query->whereIn('id', $productIds);
        }

        $query->chunkById(500, function ($products) {
            DB::transaction(function () use ($products) {
                DB::table('product_barcodes')->whereIn('product_id', $products->pluck('id'))->delete();
                $barcodes = [];

                foreach ($products as $product) {
                    $codes = array_values(array_filter(array_map(fn ($code) => is_scalar($code) ? trim((string) $code) : '', (array) $product->barcodes)));
                    $names = $product->getTranslations('name') ?: [(string) $product->getRawOriginal('name')];

                    DB::table('products')->where('id', $product->id)->update([
                        'search_text' => mb_strtolower(implode(' ', array_filter([...array_values($names), (string) $product->code, ...$codes]))),
                    ]);

                    foreach ($codes as $code) {
                        $barcodes[$code] = ['code' => $code, 'product_id' => $product->id];
                    }
                }

                if ($barcodes !== []) {
                    DB::table('product_barcodes')->upsert(array_values($barcodes), ['code'], ['product_id']);
                }
            });
        });
    }

    /**
     * Products whose search text has every word of the term (any order).
     *
     * @param  Builder<Product>  $query
     */
    public static function search($query, string $term)
    {
        foreach (preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where('products.search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $query;
    }
}
