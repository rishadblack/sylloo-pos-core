<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\Vat;
use Rishadblack\SyllooPosCore\Services\ProductSearchIndex;
use Rishadblack\SyllooPosCore\Tests\TestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');

pest()->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Till data for the sale desk's tests
|--------------------------------------------------------------------------
*/

/**
 * A product on sale, priced like a pull from the ERP leaves it (net_sale_price: one unit with VAT).
 *
 * @param  array<string, mixed>  $attributes
 */
function tillProduct(string $name, float $priceWithVat, ?Vat $vat = null, array $attributes = []): Product
{
    $vatAmount = $vat !== null ? round($priceWithVat - $priceWithVat / (1 + $vat->percentage / 100), 6) : 0.0;

    $product = Product::query()->create([
        'code' => $attributes['code'] ?? 'P'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'name' => ['en' => $name, 'bn' => $name],
        'sale_price' => $priceWithVat - $vatAmount,
        'net_sale_price' => $priceWithVat,
        'sale_vat_id' => $vat?->id,
        'sale_vat_amount' => $vatAmount,
        'status' => 1,
        ...Arr::except($attributes, ['code']),
    ]);

    app(ProductSearchIndex::class)->rebuild([(string) $product->id]);

    return $product->refresh();
}

function tillVat(float $percentage, int $type): Vat
{
    return Vat::query()->create(['name' => 'VAT '.$percentage, 'type' => $type, 'percentage' => $percentage, 'status' => 1]);
}

function tillCash(): PaymentMethod
{
    return PaymentMethod::query()->where('is_cash', true)->first()
        ?? PaymentMethod::query()->create(['name' => 'Cash', 'is_cash' => true, 'is_default' => 1, 'serial' => 1, 'status' => 1]);
}

function tillCard(): PaymentMethod
{
    return PaymentMethod::query()->create(['name' => 'Card', 'is_cash' => false, 'serial' => 2, 'status' => 1]);
}

/**
 * An activated till (number 42) with the business's default customer group, its walk-in customer and cash.
 */
function readyTill(): void
{
    $now = now()->toDateTimeString();
    $groupId = (string) Str::ulid();

    DB::table('contact_groups')->insert(['id' => $groupId, 'name' => 'Customers', 'is_default' => 1, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('contacts')->insert([
        'id' => (string) Str::ulid(), 'type' => 1, 'contact_group_id' => $groupId, 'desktop_pos_id' => (string) Str::ulid(),
        'name' => 'Walk-in', 'is_walk_in' => 1, 'status' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    tillCash();
    Settings::set('desktop_pos_id', '42');
}
