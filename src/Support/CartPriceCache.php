<?php

namespace Rishadblack\SyllooPosCore\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The cart's priced data kept between requests (audit M2): the items, their VAT and the customer group's prices are
 * read once per cart and group, not again on every quantity change. Anything that changes a price (a pull, a product,
 * a group price, a VAT rate or a unit saved here) starts a new version, so nothing stale is ever used; the sale itself
 * is still priced exactly when it is saved.
 */
class CartPriceCache
{
    public const VERSION_KEY = 'terminal.price_version';

    public const MINUTES = 30;

    /**
     * @template T
     *
     * @param  list<string>  $productIds
     * @param  Closure(): T  $read
     * @return T
     */
    public static function remember(string $what, array $productIds, ?string $contactGroupId, Closure $read): mixed
    {
        sort($productIds);
        $key = 'terminal.cart_prices.'.$what.'.'.(int) Cache::get(self::VERSION_KEY, 0).'.'.md5(implode(',', $productIds).'|'.$contactGroupId);

        return Cache::remember($key, now()->addMinutes(self::MINUTES), $read);
    }

    /**
     * Something that prices the cart changed: what was kept is not used again.
     */
    public static function forget(): void
    {
        if (! Cache::add(self::VERSION_KEY, 1)) {
            Cache::increment(self::VERSION_KEY);
        }
    }
}
