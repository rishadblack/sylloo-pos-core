<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooPosCore\Services\TerminalProfile;
use Throwable;

/**
 * Whether this till works for a restaurant, café or bar (erp: setting `pos_restaurant_mode`, sent in the terminal
 * profile). Off means a shop's till: no panel, key, field, ticket or pull of tables. A profile from before the switch,
 * or none at all, counts as off, and an unreadable one too, so a page never breaks over it.
 */
final class RestaurantMode
{
    public static function isOn(): bool
    {
        try {
            return (app(TerminalProfile::class)->current()['features']['restaurant'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The business's service charge on a table, in percent (owner decision 7a); 0 when it has none.
     */
    public static function serviceChargePercent(): float
    {
        try {
            return max(0.0, min(100.0, (float) (app(TerminalProfile::class)->current()['features']['service_charge_percent'] ?? 0)));
        } catch (Throwable) {
            return 0.0;
        }
    }
}
