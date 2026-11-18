<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Events\TerminalStateChanged;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\SyncError;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\AuditTrail;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Rishadblack\SyllooPosCore\Services\ErpCircuitBreaker;
use Rishadblack\SyllooPosCore\Services\ErpLicenceCheck;
use Rishadblack\SyllooPosCore\Services\ErpOrderedReset;
use Rishadblack\SyllooPosCore\Services\SaleChain;
use Rishadblack\SyllooPosCore\Services\StaffDirectory;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;
use Rishadblack\SyllooPosCore\Services\SyncStatusService;
use Rishadblack\SyllooPosCore\Services\TableBills;
use Rishadblack\SyllooPosCore\Services\TerminalProfile;
use Rishadblack\SyllooPosCore\Support\AppVersion;
use Rishadblack\SyllooPosCore\Support\DeviceState;
use Throwable;

/**
 * Report this terminal's sync health to the ERP: the order counts, the failed jobs and the sync
 * errors not reported yet. Never sends order contents, amounts or customer details.
 *
 * The reply tells whether the terminal's outlet is set on the ERP; when it is and orders are
 * waiting, a push is started, so sales queued while the outlet was missing go up on their own.
 * It also carries the ERP's lock state and licence expiry, which the till takes (audit A6), and a reset the ERP
 * ordered, which runs on the next page load (ErpOrderedReset, audit A7).
 */
class SendHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const REASONS = ['start', 'interval', 'reconnect', 'failure'];

    /**
     * Seconds during which a burst of failures or reconnects sends a single heartbeat.
     */
    public const DEBOUNCE_SECONDS = 60;

    /**
     * The ERP being unreachable is not a failure of this job: it is never retried.
     */
    public int $tries = 1;

    /**
     * Seconds the queued heartbeat stays unique.
     */
    public int $uniqueFor = 60;

    /**
     * @param  'start'|'interval'|'reconnect'|'failure'  $reason
     */
    public function __construct(public string $reason = 'interval')
    {
        // Small and it can start a push, so it never waits behind a pull or image downloads.
        $this->onQueue(PushErpJob::QUEUE);
    }

    /**
     * One queued heartbeat per reason: a queued failure heartbeat never swallows the reconnect one, which pulls.
     */
    public function uniqueId(): string
    {
        return $this->reason;
    }

    /**
     * Send a heartbeat after a sync failure or a reconnect, at most once per {@see self::DEBOUNCE_SECONDS} for each
     * reason, so a burst of push failures can not swallow the catch-up pull of a reconnect.
     *
     * @param  'reconnect'|'failure'  $reason
     */
    public static function dispatchDebounced(string $reason): void
    {
        if (! Cache::add('pos.heartbeat.debounce.'.$reason, true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        try {
            self::dispatch($reason);
        } catch (Throwable $exception) {
            Log::warning('Heartbeat: could not queue the heartbeat: '.$exception->getMessage());
        }
    }

    /**
     * True when the terminal is activated, which the heartbeat needs to identify itself.
     */
    public static function terminalActivated(): bool
    {
        return Settings::get('activation_key') && Settings::get('desktop_pos_id');
    }

    /**
     * Never throws: a heartbeat that could not be sent is only logged, so it never fills failed_jobs.
     */
    public function handle(): void
    {
        try {
            $this->send();
        } catch (Throwable $exception) {
            Log::warning('Heartbeat: could not send the heartbeat: '.$exception->getMessage(), ['reason' => $this->reason]);
        }
    }

    protected function send(): void
    {
        if (! self::terminalActivated()) {
            return;
        }

        $errors = SyncErrorLog::unreported(20);
        $audit = AuditTrail::unreported();
        $payload = $this->payload($errors->all());

        if ($audit->isNotEmpty()) {
            $payload['audit'] = $audit->values()->all();
        }

        // Background calls waited for the ERP: when this heartbeat finds it back, the skipped pull runs too.
        $wasUnreachable = ErpCircuitBreaker::isOpen();

        try {
            $response = (new ErpApiService)->health($payload);
        } catch (Throwable $exception) {
            Log::info('Heartbeat: the ERP could not be reached: '.$exception->getMessage());

            return;
        }

        // The ERP released this terminal (ApiService marked it): the windows show the Releasing screen.
        if (DeviceState::isReleasedReply($response)) {
            rescue(fn () => TerminalStateChanged::dispatch(), report: false);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Heartbeat: the ERP refused the heartbeat', [
                'reason' => $this->reason,
                'status' => $response->status(),
                'code' => SyncErrorLog::responseCode($response),
            ]);

            return;
        }

        SyncErrorLog::markReported($errors->pluck('id')->all());
        AuditTrail::markReported($audit->pluck('id')->all());

        $serverHealth = [
            'checked_at' => now()->toIso8601String(),
            'server_time' => $response->json('data.server_time'),
            'clock_skew_seconds' => is_numeric($response->json('data.clock_skew_seconds')) ? (int) $response->json('data.clock_skew_seconds') : null,
            'outlet_ready' => (bool) $response->json('data.outlet_ready', false),
            'terminal_status' => $response->json('data.terminal_status'),
            // How the ERP taxes a discounted sale; the till works out its totals the same way.
            'vat_after_discount' => (bool) ($response->json('data.settings.vat_after_discount') ?? true),
            // The business's cash rounding step (0 while off); a sale paid only in cash is rounded to it.
            'cash_rounding_step' => is_numeric($response->json('data.settings.cash_rounding_step')) ? (float) $response->json('data.settings.cash_rounding_step') : 0.0,
        ];

        SyncStatusService::rememberServerHealth($serverHealth);
        Order::rememberTerminalSerial($response->json('data.terminal_serial'));

        // The device state (plan, blocked, minimum version), the outlet, the lock (audit A6) and a reset the ERP
        // ordered (audit A7, run on the next page).
        if ($this->applyTerminalState($response->json('data'), ErpLicenceCheck::answerFrom($response))) {
            rescue(fn () => TerminalStateChanged::dispatch(), report: false);
        }

        // Who may work here or approve changed (a PIN, a permission, a person) since this terminal's list.
        if (app(StaffDirectory::class)->isOlderThan($response->json('data.staff_version')) && User::latestWithValidToken()) {
            PullStaff::dispatch();
        }

        // The till opened online: PINs changed on other devices are fetched (`GET me`).
        if ($this->reason === 'start') {
            RefreshMemberPins::dispatch();
        }

        // A shift or cash movement the ERP's cash register does not have yet (RegisterSync).
        if (RegisterSync::hasWork()) {
            RegisterSync::dispatch();
        }

        // The ERP's business or outlet details changed since this terminal's copy (TerminalProfile).
        if (app(TerminalProfile::class)->isOlderThan($response->json('data.profile_updated_at'))) {
            PullTerminalProfile::dispatch();
        }

        if ($serverHealth['outlet_ready'] && $payload['pending_orders'] > 0) {
            PushErpJob::dispatch();
        }

        // After a start, a reconnect, a time without the ERP or a pull skipped while it was away, the catalog, VAT rates
        // and prices may have changed meanwhile.
        if ((in_array($this->reason, ['start', 'reconnect'], true) || $wasUnreachable || PullStart::wasSkipped()) && User::latestWithValidToken()) {
            PullStart::forgetSkipped();
            PullStart::dispatch();
        }
    }

    /**
     * Keep the device state, outlet and lock the reply names, and note a reset it orders. Returns whether the windows
     * should look again (a screen changed or a reset waits).
     *
     * @param  array<string, mixed>|null  $licence
     */
    protected function applyTerminalState(mixed $data, ?array $licence): bool
    {
        $lockChanged = $licence !== null && app(ErpLicenceCheck::class)->rememberFromHeartbeat($licence);
        $resetWaits = is_array($data) && app(ErpOrderedReset::class)->noteReply($data);

        return $lockChanged || $resetWaits;
    }

    /**
     * The heartbeat body: counts, times, error texts and the sale chain's numbers and hashes only.
     *
     * @param  array<int, SyncError>  $errors
     * @return array{reason: string, app_version: string, os: ?string, client_time: string, pending_orders: int, uploading_orders: int, rejected_orders: int, failed_jobs: int, oldest_pending_at: ?string, last_push_at: ?string, last_pull_at: ?string, errors: array<int, array{at: string, type: string, code: ?string, order_id: ?string, message: string}>, chain?: array{id: string, last_serial: int, head: ?string, unsent: list<int>}}
     */
    public function payload(array $errors = []): array
    {
        $oldestPendingAt = Order::syncPending()->min('orders.created_at');
        $lastPushAt = Order::max('orders.last_sync_at');
        $lastPullAt = collect(Settings::get('last_synced_by_model', []) ?? [])
            ->filter()
            ->map(fn (mixed $date) => rescue(fn () => Carbon::parse($date), null, false))
            ->filter()
            ->max();

        return [
            'reason' => in_array($this->reason, self::REASONS, true) ? $this->reason : 'interval',
            'app_version' => mb_substr((string) AppVersion::current(), 0, 50),
            'os' => mb_substr(trim(PHP_OS_FAMILY.' '.php_uname('r')), 0, 100) ?: null,
            'client_time' => now()->toIso8601String(),
            ...ErpOrderedReset::syncCounts(),
            'oldest_pending_at' => $oldestPendingAt ? Carbon::parse($oldestPendingAt)->toIso8601String() : null,
            'last_push_at' => $lastPushAt ? Carbon::parse($lastPushAt)->toIso8601String() : null,
            'last_pull_at' => $lastPullAt?->toIso8601String(),
            'errors' => collect($errors)->map(fn ($error) => [
                'at' => $error->occurred_at->toIso8601String(),
                'type' => $error->type,
                'code' => $error->code,
                'order_id' => $error->order_id,
                'message' => SyncErrorLog::truncate((string) $error->message),
            ])->values()->all(),
            // The chain of completed sales: the ERP notices a sale deleted here before upload (SaleChain).
            ...(($chain = SaleChain::forHeartbeat()) !== null ? ['chain' => $chain] : []),
            // A restaurant's open tables for the office (erp: docs/restaurant.md, phase 9): a summary only, read-only.
            ...(($openTables = app(TableBills::class)->heartbeatSummary()) !== null ? ['open_tables' => $openTables] : []),
        ];
    }
}
