<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;
use Rishadblack\SyllooPosCore\Facades\QueueWorker;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;

/**
 * The till's steps in the shared base's release frame (plan §4.3 "Release steps": send sales first, then wipe the
 * till's data). The order is the base's ({@see DeviceRelease}); what is sent and what is removed is the till's:
 *
 * - stop: the cashier is signed out (their cart stays saved until the clean-up). An open shift stays open: the office
 *   takes no close without counted cash, so its register is closed in Sylloo.
 * - send: sales, payments for old dues and the register, with the last valid cashier token (the office still takes them
 *   from a released terminal). Sales the office refused keep the terminal on Releasing.
 * - remove: every table's data, the business settings, pictures, backups, the key and the activation, keeping only the
 *   computer's own settings (printer, print settings, look, language) — {@see LocalDataReset}.
 */
class TerminalReleaseSteps implements ReleaseSteps
{
    public function deviceApi(): DeviceApi
    {
        return new ErpApiService;
    }

    public function stopWork(): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            CashierSession::logout($user);
            Auth::logout();
        }
    }

    public function refusedWork(): int
    {
        return DeviceRelease::refusedSales();
    }

    public function hasWaitingWork(): bool
    {
        return DeviceRelease::waitingSales() > 0 || RegisterSync::hasWork();
    }

    public function sendWaitingWork(): void
    {
        QueueWorker::up('default');

        rescue(fn () => PushErpJob::dispatch(), report: false);

        if (RegisterSync::hasWork()) {
            rescue(fn () => RegisterSync::dispatch(), report: false);
        }
    }

    public function removeLocalData(): void
    {
        // The office's register close needs counted cash, and nobody counted it: an open shift is not closed here
        // (its sales and cash movements went up), so the office closes that register itself.
        if (Shift::query()->open()->exists()) {
            Log::warning('Release: an open shift was left open; the office must close its register (cash not counted).');
        }

        app(LocalDataReset::class)->handle(keepComputerSettings: true);
    }

    public function afterClean(): void
    {
        QueueWorker::up('default');
        QueueWorker::up('images');
    }
}
