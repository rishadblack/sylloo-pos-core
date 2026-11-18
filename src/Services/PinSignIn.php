<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooAppBase\Pin\PinSignIn as BasePinSignIn;
use Rishadblack\SyllooPosCore\Support\DeviceState;

/**
 * The member's PIN on this terminal (../sylloo-erp-docs/erp/device-activation-plan.md §5.4, §6.5). The rules are the
 * shared base's ({@see BasePinSignIn}): checked here against the hash the till keeps (the staff list's `pin_hash`, or
 * the hash kept when the member created the PIN here, {@see CashierDirectory}), never sent except to `POST pin`. Five
 * wrong PINs in a row end that member's sign-in on this terminal: the till tells the office (`POST sign-out`, when
 * online) and forgets it, so they must use Sign in with browser again. Other members are not touched.
 */
class PinSignIn extends BasePinSignIn
{
    public function __construct(?CashierDirectory $cashiers = null)
    {
        parent::__construct($cashiers ?? app(CashierDirectory::class), DeviceState::instance());
    }

    /**
     * Why a new PIN may not be used, as one of the till's translation keys (`device.pin_format`,
     * `device.pin_too_easy`), or null when it is fine. The rule is the base's; the words stay the till's.
     */
    public static function problemWith(string $pin): ?string
    {
        $problem = parent::problemWith($pin);

        return $problem === null ? null : str_replace('sylloo-app-base::', '', $problem);
    }
}
