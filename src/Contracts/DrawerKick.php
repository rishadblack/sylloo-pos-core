<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * Sends the cash drawer's kick to the receipt printer's drawer port. The PC till: a RAW job through the Windows print
 * spooler (the core's default); the Android till: its native printer job.
 */
interface DrawerKick
{
    /**
     * Send the bytes as they are (no page, no driver text). Never throws; returns whether the printer took them.
     *
     * @param  list<int>  $bytes  the kick, e.g. ESC p 0 25 250
     * @param  string|null  $printer  the printer's name; null: the system's default printer
     */
    public function kick(array $bytes, ?string $printer): bool;
}
