<?php

namespace Rishadblack\SyllooPosCore\Support;

/**
 * Serial numbers as the cashier enters them: a scan, a typed number or a pasted list.
 */
final class SerialNumbers
{
    /**
     * Longest serial number the ERP keeps.
     */
    public const MAX_LENGTH = 100;

    /**
     * A scan or paste split into serial numbers: one per line, or separated by commas, semicolons or tabs.
     *
     * @return list<string>
     */
    public static function split(string $input): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\r\n,;\t]+/', $input) ?: []),
            fn (string $serial) => $serial !== '',
        ));
    }
}
