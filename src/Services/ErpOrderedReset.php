<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Facades\QueueWorker;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Jobs\SendHeartbeat;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Support\AppVersion;
use Rishadblack\SyllooPosCore\Support\DeviceState;
use Throwable;

/**
 * A hard reset the ERP orders (audit A7, owner decision 2026-10-28; erp: Modules/Pos TerminalResets): every activation
 * and heartbeat reply says `reset.required`, its `reason` and whether it is `allowed` (no sale waits on the terminal by
 * the ERP's last heartbeat). The till obeys it:
 *
 * - `activation`: the activation itself was the reset (a different code started the terminal fresh, the same code
 *   keeps its data, owner rule 2026-10-26), so it is only reported.
 * - `release`: waits until this till runs the announced release (the update installs it), then resets.
 * - `practice_reset` and any other reason: resets, keeping the licence.
 * - `admin` (a force reset, the terminal is `released`): resets although sales wait (the ERP discarded them), and the
 *   ERP frees the licence when it records the reset, so everything goes, the licence too, and the activation page opens.
 *
 * The heartbeat only notes the order ({@see self::noteReply()}); the wipe runs on the next page load
 * (the PC till's RunErpOrderedReset middleware), never inside a sale request, and only while nothing waits here: no
 * sale to upload, no failed upload, no shift or cash movement for the ERP's register, no open shift, no held sale and
 * no cart kept for the signed-in cashier. The ERP is asked first (`POST v1/reset`, its own sync gate, which records the
 * reset); once it agrees, LocalDataReset wipes the terminal and the licence is put back, so the cashiers sign in again
 * and the catalogue is pulled again.
 */
class ErpOrderedReset
{
    /** The NativePHP setting holding the noted order: reason and when it was noted. */
    public const SETTING = 'erp_reset_order';

    public const REASON_ACTIVATION = 'activation';

    public const REASON_RELEASE = 'release';

    /** An admin force reset (erp: ForceResetDesktopPos): the terminal is released and its waiting sales discarded. */
    public const REASON_ADMIN = 'admin';

    /** The reset ran: the terminal is empty and keeps its licence. */
    public const DONE = 'done';

    /** Something still waits on the terminal; the order is forgotten until the next heartbeat notes it again. */
    public const WAITING = 'waiting';

    /** The ERP refused it, or could not be asked; the order is forgotten until the next heartbeat. */
    public const REFUSED = 'refused';

    /** The reset ran and the ERP released the terminal (an admin force reset): everything is gone, the licence too. */
    public const RELEASED = 'released';

    /**
     * NativePHP settings of the licence, put back after the wipe: the terminal stays activated.
     *
     * @var list<string>
     */
    public const LICENCE_SETTINGS = [...DeviceState::SETTINGS, DeviceState::DEVICE_UID_SETTING];

    /**
     * The noted order, or null.
     *
     * @return array{reason: ?string, noted_at: string}|null
     */
    public static function pending(): ?array
    {
        $order = rescue(fn () => Settings::get(self::SETTING), null, false);

        return is_array($order) && array_key_exists('noted_at', $order) ? $order : null;
    }

    public static function forget(): void
    {
        if (self::pending() !== null) {
            rescue(fn () => Settings::set(self::SETTING, null), report: false);
        }
    }

    /**
     * The sales still waiting on this terminal, by the ERP's sync counters (erp: TerminalResets::SYNC_COUNTERS).
     *
     * @return array{pending_orders: int, uploading_orders: int, rejected_orders: int, failed_jobs: int}
     */
    public static function syncCounts(): array
    {
        return [
            'pending_orders' => Order::syncPending()->count(),
            'uploading_orders' => Order::syncUploading()->count(),
            'rejected_orders' => Order::syncFailed()->count(),
            'failed_jobs' => SyncStatusService::failedUploadsCount(),
        ];
    }

    /**
     * What else waits here that a wipe would lose, in words, or null when nothing does.
     */
    public static function localWorkWaiting(): ?string
    {
        return match (true) {
            array_sum(self::syncCounts()) > 0 => 'sales still wait to upload',
            RegisterSync::hasWork() => 'shifts or cash movements still wait to upload',
            Shift::query()->open()->exists() => 'a shift is still open',
            Order::query()->draft()->exists() => 'held sales are still on the terminal',
            Auth::id() !== null && Cache::has('terminal.cart.'.Auth::id()) => 'a cart is still being made',
            default => null,
        };
    }

    /**
     * Read the reset state of an activation or heartbeat reply (`data`). A reply without one (an older ERP) changes
     * nothing. Returns whether a reset now waits for the next page load.
     *
     * @param  array<string, mixed>  $data
     */
    public function noteReply(array $data): bool
    {
        $reset = $data['reset'] ?? null;

        if (! is_array($reset)) {
            return false;
        }

        $reason = is_string($reset['reason'] ?? null) ? $reset['reason'] : null;

        if (($reset['required'] ?? false) !== true || ($reason === self::REASON_RELEASE && ! $this->runsRelease($data['update'] ?? null))) {
            self::forget();

            return false;
        }

        if ($reason === self::REASON_ACTIVATION) {
            self::forget();
            $this->reportActivation();

            return false;
        }

        if (($reset['allowed'] ?? false) !== true) {
            self::forget();

            return false;
        }

        if (self::pending() === null) {
            Log::info('ERP reset: the ERP ordered a reset of this terminal; it runs on the next page load', ['reason' => $reason]);
        }

        Settings::set(self::SETTING, ['reason' => $reason, 'noted_at' => now()->toIso8601String()]);

        return true;
    }

    /**
     * Run the noted reset now (a page load, never a sale request). Returns null without one.
     *
     * @return self::DONE|self::RELEASED|self::WAITING|self::REFUSED|null
     */
    public function run(): ?string
    {
        $order = self::pending();

        if ($order === null) {
            return null;
        }

        self::forget();

        if (! SendHeartbeat::terminalActivated()) {
            return null;
        }

        $forcedByAdmin = $order['reason'] === self::REASON_ADMIN;

        // A force reset discarded the waiting sales in the ERP already: nothing here holds it back.
        if (! $forcedByAdmin && ($waiting = self::localWorkWaiting()) !== null) {
            Log::info('ERP reset: postponed, '.$waiting);

            return self::WAITING;
        }

        $reply = $this->askErp();

        if ($reply === null) {
            return self::REFUSED;
        }

        if ($forcedByAdmin || ($reply['terminal_status'] ?? null) === 'released') {
            app(LocalDataReset::class)->handle();
            Log::info('ERP reset: the terminal was reset and released', ['reason' => $order['reason']]);

            return self::RELEASED;
        }

        $this->wipeKeepingLicence();
        Log::info('ERP reset: the terminal was reset', ['reason' => $order['reason']]);

        return self::DONE;
    }

    /**
     * Whether this till already runs the release a `release` reset waits for (no pending update: it does).
     *
     * @param  mixed  $update  the reply's `update`: release, ready_at, deadline, postponed_until, forced
     */
    protected function runsRelease(mixed $update): bool
    {
        $release = is_array($update) && is_string($update['release'] ?? null) ? $update['release'] : null;

        return $release === null || version_compare(ltrim((string) AppVersion::current(), 'vV'), ltrim($release, 'vV'), '>=');
    }

    /**
     * The activation reset is the activation itself: tell the ERP, so it stops asking. Refused while sales wait (the
     * ERP asks again on the next heartbeat).
     */
    protected function reportActivation(): void
    {
        if (array_sum(self::syncCounts()) > 0) {
            return;
        }

        $this->askErp();
    }

    /**
     * Ask the ERP to run the reset (its sync gate, skipped for a force reset), which records it. Returns its reply
     * (`reset_at`, `release`, `terminal_status` and the reset state), or null when it did not agree.
     *
     * @return array<string, mixed>|null
     */
    protected function askErp(): ?array
    {
        try {
            $response = (new ErpApiService)->reset([
                'app_version' => mb_substr((string) AppVersion::current(), 0, 50),
                ...self::syncCounts(),
            ]);
        } catch (Throwable $exception) {
            Log::info('ERP reset: the ERP could not be reached: '.$exception->getMessage());

            return null;
        }

        if (! $response->successful()) {
            SyncErrorLog::recordResponse('reset', $response, 'Reset refused: ');

            return null;
        }

        $data = rescue(fn () => $response->json('data'), null, false);

        return is_array($data) ? $data : [];
    }

    /**
     * Wipe the terminal (LocalDataReset) and put its licence and terminal number back, then start the queue workers
     * again, which the wipe stopped.
     */
    protected function wipeKeepingLicence(): void
    {
        $licence = [];

        foreach (self::LICENCE_SETTINGS as $key) {
            $licence[$key] = Settings::get($key);
        }

        $terminalSerial = rescue(fn () => Setting::valueOf(Order::TERMINAL_SERIAL_SETTING), null, false);

        app(LocalDataReset::class)->handle();

        foreach (array_filter($licence, fn (mixed $value) => $value !== null) as $key => $value) {
            Settings::set($key, $value);
        }

        Order::rememberTerminalSerial($terminalSerial);

        QueueWorker::up('default');
        QueueWorker::up('images');
    }
}
