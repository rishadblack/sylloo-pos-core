<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Models\Setting;

/**
 * The shop's own quick keys (audit §2.3): the products it rings up most, pinned from the product grid and shown on the
 * Quick keys tab. Kept on this till (setting `quick_keys`), at most MAX.
 */
class QuickKeys
{
    public const SETTING = 'quick_keys';

    public const MAX = 60;

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        $ids = json_decode((string) Setting::valueOf(self::SETTING), true);

        return is_array($ids) ? array_values(array_filter($ids, 'is_string')) : [];
    }

    public static function toggle(string $productId): void
    {
        $ids = self::ids();

        $ids = in_array($productId, $ids, true)
            ? array_values(array_diff($ids, [$productId]))
            : array_slice([...$ids, $productId], -self::MAX);

        Setting::put(self::SETTING, json_encode($ids));
    }
}
