<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * Draws the order code printed on a receipt. The core's default draws Code 128 itself (no barcode package, no
 * `ext-gd`), on every till alike.
 */
interface ReceiptBarcode
{
    /**
     * The barcode as an SVG document, or null when the code can not be drawn (then no barcode is printed).
     *
     * @param  string  $type  the symbology the shop chose (setting `receipt_barcode_type`, `C128` by default)
     */
    public function svg(string $code, string $type): ?string;
}
