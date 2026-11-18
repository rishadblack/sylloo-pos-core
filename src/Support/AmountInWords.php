<?php

namespace Rishadblack\SyllooPosCore\Support;

use Illuminate\Support\Str;

/**
 * An amount in words with its currency for receipts, e.g. 452.50 => "FOUR HUNDRED FIFTY TWO TAKA AND FIFTY POISA
 * ONLY". The currency defaults to config('app.currency_name') / config('app.currency_subunit').
 */
final class AmountInWords
{
    public static function format(mixed $value, ?string $currency = null, ?string $subunit = null): string
    {
        $amount = round(abs((float) preg_replace('/[^0-9.-]/', '', (string) $value)), 2);

        if ($amount == 0) {
            return '';
        }

        $currency = Str::upper($currency ?: config('app.currency_name', 'Taka'));
        $subunit = Str::upper($subunit ?: config('app.currency_subunit', 'Poisa'));

        $whole = (int) floor($amount);
        $fraction = (int) round(($amount - $whole) * 100);

        $words = trim(convertNumberToWordInEnglish($whole, ' ')).' '.$currency;

        if ($fraction > 0) {
            $words .= ' AND '.trim(convertNumberToWordInEnglish($fraction, ' ')).' '.$subunit;
        }

        return preg_replace('/\s+/', ' ', $words).' ONLY';
    }
}
