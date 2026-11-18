<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * Hands a finished, self-contained printout (receipt, kitchen ticket, shift report: one HTML page with everything
 * inline) to a printer. The PC till: Electron prints the page; the Android till draws it as a picture for an ESC/POS
 * printer.
 */
interface ReceiptPrinter
{
    /**
     * Print the page. Throws when it can not be handed to the printer.
     *
     * @param  string|null  $printer  the printer's name; null: the system's default printer
     * @param  array{print_mode: string, copies: int|string, margins: string, paper_width: int|string}  $settings  the receipt settings: `print_mode` silent or dialog, `copies`, `margins` (Electron's margin type), `paper_width` in mm
     */
    public function print(string $html, ?string $printer, array $settings): void;

    /**
     * The installed printers as name => display name. Throws when they can not be listed.
     *
     * @return array<string, string>
     */
    public function printers(): array;
}
