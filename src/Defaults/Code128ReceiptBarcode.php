<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Rishadblack\SyllooPosCore\Contracts\ReceiptBarcode;
use Rishadblack\SyllooPosCore\Support\Barcode\Code128Svg;

/**
 * The receipt's barcode on every till: Code 128, drawn by the core. Receipts only print Code 128 (`C128`, the only
 * value the ERP and the tills use), so any other type is drawn as Code 128 too.
 */
class Code128ReceiptBarcode implements ReceiptBarcode
{
    public function svg(string $code, string $type): ?string
    {
        return Code128Svg::svg($code, 1.4, 40, 'black');
    }
}
