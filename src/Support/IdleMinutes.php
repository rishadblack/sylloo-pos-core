<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Models\Setting;

/**
 * The idle minutes before a till locks (setting `idle_lock_minutes`; 0 never locks). The lock itself is the till's.
 */
final class IdleMinutes
{
    public const DEFAULT_MINUTES = 10;

    public static function minutes(): int
    {
        $minutes = rescue(fn () => Setting::valueOf('idle_lock_minutes'), null, false);

        return is_numeric($minutes) ? max(0, (int) $minutes) : self::DEFAULT_MINUTES;
    }
}
