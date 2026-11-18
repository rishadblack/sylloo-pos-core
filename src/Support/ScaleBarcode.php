<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Models\Setting;

/**
 * Barcodes printed by a shop scale (audit §2.3, weighed / price-embedded barcodes): EAN-13 made of a prefix the shop
 * sets (e.g. 20–29), the item's code (5 digits by default), the weight or the price (the rest) and a check digit.
 * "2100123012504" with prefix 21, item 00123, weight 01250 in grams is 1.250 kg of item 123. Off unless the shop
 * names its prefixes (Settings).
 */
class ScaleBarcode
{
    public const PREFIXES = 'scale_barcode_prefixes';

    public const KIND = 'scale_barcode_kind';

    public const ITEM_DIGITS = 'scale_barcode_item_digits';

    public const DECIMALS = 'scale_barcode_decimals';

    /** @var array<string, string> */
    public const KINDS = ['weight' => 'Weight (quantity)', 'price' => 'Price (amount)'];

    /**
     * @return array{prefixes: list<string>, kind: string, item_digits: int, decimals: int}
     */
    public static function settings(): array
    {
        $prefixes = array_values(array_filter(array_map('trim', explode(',', (string) Setting::valueOf(self::PREFIXES))), fn (string $prefix) => preg_match('/^\d{1,3}$/', $prefix) === 1));
        $kind = (string) Setting::valueOf(self::KIND);

        return [
            'prefixes' => $prefixes,
            'kind' => array_key_exists($kind, self::KINDS) ? $kind : 'weight',
            'item_digits' => max(3, min(7, (int) Setting::valueOf(self::ITEM_DIGITS))),
            'decimals' => max(0, min(3, (int) Setting::valueOf(self::DECIMALS))),
        ];
    }

    /**
     * The item code and its weight (`quantity`) or price (`price`), or null when the code is not a scale barcode.
     *
     * @return array{item_code: string, quantity: ?float, price: ?float}|null
     */
    public static function parse(string $code): ?array
    {
        $code = trim($code);
        $settings = self::settings();

        if ($settings['prefixes'] === [] || preg_match('/^\d{13}$/', $code) !== 1 || ! self::checkDigitIsRight($code)) {
            return null;
        }

        foreach ($settings['prefixes'] as $prefix) {
            if (! str_starts_with($code, $prefix)) {
                continue;
            }

            $item = substr($code, strlen($prefix), $settings['item_digits']);
            $digits = substr($code, strlen($prefix) + $settings['item_digits'], 12 - strlen($prefix) - $settings['item_digits']);

            if ($item === '' || $digits === '') {
                return null;
            }

            $value = (int) $digits / (10 ** $settings['decimals']);

            if ($value <= 0) {
                return null;
            }

            return [
                'item_code' => $item,
                'quantity' => $settings['kind'] === 'weight' ? round($value, 3) : null,
                'price' => $settings['kind'] === 'price' ? round($value, 2) : null,
            ];
        }

        return null;
    }

    /**
     * The codes the item may have on the till: as printed ("00123") and without its leading zeros ("123").
     *
     * @return list<string>
     */
    public static function itemCodes(string $item): array
    {
        return array_values(array_unique(array_filter([$item, ltrim($item, '0')], fn (string $code) => $code !== '')));
    }

    protected static function checkDigitIsRight(string $code): bool
    {
        $sum = 0;

        foreach (str_split(substr($code, 0, 12)) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $code[12];
    }
}
