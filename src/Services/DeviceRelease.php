<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Rishadblack\SyllooAppBase\Release\DeviceRelease as BaseDeviceRelease;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Support\DeviceState;

/**
 * A released terminal cleans itself (../sylloo-erp-docs/erp/device-api.md §3, plan §6.7, decision 20 "send first,
 * then remove"). The frame is the shared base's ({@see BaseDeviceRelease}); the steps are the till's
 * ({@see TerminalReleaseSteps}). The ERP answers `device_released` (401, or `blocked` in the state and the heartbeat);
 * then:
 *
 * 1. The terminal stops selling at once (the Releasing screen, DeviceState::screen()) and the cashier is signed out
 *    (their cart stays saved until the clean-up). An open shift stays open: the office takes no close without counted
 *    cash, so its register is closed in Sylloo.
 * 2. It keeps sending what waits: sales, payments for old dues and the register, with the last valid cashier token
 *    (the office still takes them from a released terminal).
 * 3. When nothing waits it removes every table's data, the business settings, pictures, backups, the key and the
 *    activation, keeping only the computer's own settings (printer, print settings, look, language), says
 *    `POST released`, and the Activate screen shows "This device was released from …".
 *
 * Sales the office refused stay, and the terminal stays on the Releasing screen until someone resolves them in Sylloo.
 */
class DeviceRelease extends BaseDeviceRelease
{
    public function __construct(?TerminalReleaseSteps $steps = null)
    {
        parent::__construct(DeviceState::instance(), $steps ?? app(TerminalReleaseSteps::class));
    }

    /**
     * Sales the office refused (rejected; a server problem is tried again and is not counted): they keep the terminal
     * on Releasing.
     */
    public static function refusedSales(): int
    {
        return self::unsent()
            ->whereNotNull('orders.sync_response')
            ->whereNull('orders.sync_retry_at')
            ->count();
    }

    /**
     * Sales and payments for old dues that still go up.
     */
    public static function waitingSales(): int
    {
        return self::unsent()
            ->where(fn (Builder $query) => $query->whereNull('orders.sync_response')->orWhereNotNull('orders.sync_retry_at'))
            ->count();
    }

    /**
     * Completed sales of this terminal the office does not have yet (office copies and held orders excluded).
     */
    protected static function unsent(): Builder
    {
        return Order::query()->notDraft()->where('orders.from_office', false)->whereNull('orders.last_sync_at');
    }
}
