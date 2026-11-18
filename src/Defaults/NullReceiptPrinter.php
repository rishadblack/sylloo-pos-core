<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Rishadblack\SyllooPosCore\Contracts\ReceiptPrinter;
use RuntimeException;

/**
 * A receipt printer when a till binds none: nothing can be printed and no printer is listed.
 */
class NullReceiptPrinter implements ReceiptPrinter
{
    public function print(string $html, ?string $printer, array $settings): void
    {
        throw new RuntimeException('This till has no receipt printer.');
    }

    public function printers(): array
    {
        return [];
    }
}
