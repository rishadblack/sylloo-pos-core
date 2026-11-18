<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Events\SyncStatusChanged;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\PullErpJob;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\AppVersion;

/**
 * Live sync status shown in the tray panel.
 *
 * The tray panel is pushed a {@see SyncStatusChanged} event whenever this data changes and then
 * re-fetches itself without a session (a session cookie written by a background request can
 * overwrite the one the main window just received on login and sign the cashier out), so the
 * signed-in cashier is tracked in the cache from Laravel's Login/Logout events.
 */
class SyncStatusService
{
    public const SIGNED_IN_CACHE_KEY = 'pos.signed_in_cashier';

    /**
     * Tell the tray panel to redraw. Deferred and named, so the many changes made during one
     * request or queued job (order saves, dispatched jobs, ...) reach the panel as a single event.
     */
    public static function notifyChanged(): void
    {
        defer(fn () => SyncStatusChanged::dispatch(), 'sync-status-changed')->always();
    }

    public static function rememberSignedIn(Authenticatable $user): void
    {
        cache()->forever(self::SIGNED_IN_CACHE_KEY, [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name ?? '',
        ]);

        self::notifyChanged();
    }

    public static function forgetSignedIn(): void
    {
        cache()->forget(self::SIGNED_IN_CACHE_KEY);

        self::notifyChanged();
    }

    public const UPDATE_READY_CACHE_KEY = 'pos.update_ready';

    /**
     * The last heartbeat reply of the ERP (server time, clock skew, outlet ready, terminal status).
     */
    public const SERVER_HEALTH_CACHE_KEY = 'pos.server_health';

    /**
     * @param  array{checked_at: string, server_time: ?string, clock_skew_seconds: ?int, outlet_ready: bool, terminal_status: ?string, vat_after_discount: bool, cash_rounding_step?: float}  $serverHealth
     */
    public static function rememberServerHealth(array $serverHealth): void
    {
        cache()->forever(self::SERVER_HEALTH_CACHE_KEY, $serverHealth);

        self::notifyChanged();
    }

    /**
     * @return array{checked_at: string, server_time: ?string, clock_skew_seconds: ?int, outlet_ready: bool, terminal_status: ?string, vat_after_discount: bool, cash_rounding_step?: float}|null
     */
    public function serverHealth(): ?array
    {
        $serverHealth = cache()->get(self::SERVER_HEALTH_CACHE_KEY);

        return is_array($serverHealth) ? $serverHealth : null;
    }

    /**
     * A new app version has been downloaded and installs on the next restart.
     */
    public static function rememberUpdateReady(string $version): void
    {
        cache()->forever(self::UPDATE_READY_CACHE_KEY, $version);

        self::notifyChanged();
    }

    /**
     * @param  'online'|'offline'  $status
     */
    public static function rememberConnectionStatus(string $status): void
    {
        if (cache('connection_status') !== $status) {
            cache()->put('connection_status', $status);

            self::notifyChanged();
        }
    }

    public function updateReadyVersion(): ?string
    {
        $version = cache()->get(self::UPDATE_READY_CACHE_KEY);

        // Cleared once the app is running the downloaded version.
        if ($version && version_compare($version, AppVersion::current(), '<=')) {
            cache()->forget(self::UPDATE_READY_CACHE_KEY);

            return null;
        }

        return $version;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function signedInCashier(): ?array
    {
        return cache()->get(self::SIGNED_IN_CACHE_KEY);
    }

    public function connectionStatus(): string
    {
        return cache('connection_status', 'offline');
    }

    /**
     * @return array{pending: int, uploading: int, failed: int}
     */
    public function orderCounts(): array
    {
        return [
            'pending' => Order::syncPending()->count(),
            'uploading' => Order::syncUploading()->count(),
            'failed' => Order::syncFailed()->count(),
        ];
    }

    /**
     * Queued jobs grouped by job class, e.g. ['PullErpJob' => 4, 'PushErpJob' => 1].
     *
     * @return Collection<string, int>
     */
    public function queuedJobs(): Collection
    {
        return DB::table('jobs')
            ->pluck('payload')
            ->map(fn (string $payload) => class_basename(json_decode($payload, true)['displayName'] ?? 'Job'))
            ->countBy();
    }

    public function failedJobsCount(): int
    {
        return DB::table('failed_jobs')->count();
    }

    /**
     * Failed sale uploads only. Failed pulls or image downloads hold no sale, so they must not
     * count against the ERP's sync gate (a reset or a Practice/Live switch).
     */
    public static function failedUploadsCount(): int
    {
        self::forgetFailedUploadsOnceUploaded();

        return self::failedUploadsQuery()->count();
    }

    /**
     * Once no sale waits to upload (none pending, none uploading), the failed upload jobs of before hold nothing any
     * more: they are removed, so they no longer block the ERP's sync gate. A sale the ERP rejected is reported on its
     * own (`rejected_orders`).
     */
    public static function forgetFailedUploadsOnceUploaded(): void
    {
        if (Order::syncPending()->exists() || Order::syncUploading()->exists()) {
            return;
        }

        self::failedUploadsQuery()->delete();
    }

    /**
     * The failed jobs that were sale uploads.
     */
    protected static function failedUploadsQuery(): Builder
    {
        return DB::table('failed_jobs')->where('payload', 'like', '%PushErpJob%');
    }

    /**
     * @return Collection<int, Order>
     */
    public function recentOrders(int $limit = 20): Collection
    {
        return Order::with('contact:id,name')
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, array{name: string, label: string, records: int, last_pulled_at: ?Carbon}>
     */
    public function dataTypes(): Collection
    {
        $lastSyncedByModel = Settings::get('last_synced_by_model', []) ?? [];

        return collect(PullErpJob::MODEL_MAP)->map(fn (string $modelClass, string $name) => [
            'name' => $name,
            'label' => Str::of($name)->headline()->plural()->toString(),
            'records' => $modelClass::count(),
            'last_pulled_at' => isset($lastSyncedByModel[$name]) ? Carbon::parse($lastSyncedByModel[$name]) : null,
        ])->values();
    }

    /**
     * Hours after which a sale still waiting to upload is shown as a warning in the tray.
     */
    public const LONG_WAIT_HOURS = 12;

    /**
     * A one-line warning from the last heartbeat reply, when the ERP reported a problem with this terminal.
     */
    public function serverHealthWarning(): ?string
    {
        $serverHealth = $this->serverHealth() ?? [];

        return match (true) {
            in_array($serverHealth['terminal_status'] ?? null, ['inactive', 'released'], true) => 'Server: terminal '.$serverHealth['terminal_status'].' – sales are queued',
            ($serverHealth['outlet_ready'] ?? true) === false => 'Server: outlet not set – sales are queued',
            $this->needsOnlineSignIn() => 'A cashier must sign in with the browser so the waiting sales can upload',
            (LocalBackup::oldestWaitingHours() ?? 0) >= self::LONG_WAIT_HOURS => 'Sales waiting more than '.self::LONG_WAIT_HOURS.' hours to upload – check the connection',
            ($printerProblem = rescue(fn () => app(ReceiptPrintService::class)->printerProblem(), null, false)) !== null => $printerProblem.' – check it before the next sale',
            default => null,
        };
    }

    /**
     * Sales wait to upload, but no cashier has a valid ERP token (everyone signed in offline): the push can only
     * run after an online sign-in.
     */
    public function needsOnlineSignIn(): bool
    {
        return ! User::query()->withValidToken()->exists() && PushErpJob::pendingQuery()->exists();
    }

    /**
     * Everything the tray panel renders.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $cashier = $this->signedInCashier();
        $connectionStatus = $this->connectionStatus();
        $orderCounts = $this->orderCounts();
        $queuedJobs = $this->queuedJobs();
        $dataTypes = $this->dataTypes();

        $lastPush = Order::max('last_sync_at');
        $lastSyncedAt = collect([
            $lastPush ? Carbon::parse($lastPush) : null,
            $dataTypes->pluck('last_pulled_at')->filter()->max(),
        ])->filter()->max();

        return [
            'cashier' => $cashier,
            'connectionStatus' => $connectionStatus,
            'orderCounts' => $orderCounts,
            'queuedJobs' => $queuedJobs,
            'failedJobsCount' => $this->failedJobsCount(),
            'recentOrders' => $cashier ? $this->recentOrders() : collect(),
            'dataTypes' => $dataTypes,
            'lastSyncedAt' => $lastSyncedAt,
            'updateReadyVersion' => $this->updateReadyVersion(),
            'appVersion' => AppVersion::current(),
            'serverHealthWarning' => $this->serverHealthWarning(),
            'remoteNotice' => app(RemoteConfig::class)->notice(),
            'unsupportedVersionMinimum' => app(RemoteConfig::class)->unsupportedVersionMinimum(),
            'latestVersion' => app(RemoteConfig::class)->newerVersionAvailable() ? app(RemoteConfig::class)->latestVersion() : null,
            'status' => $this->status($cashier, $connectionStatus, $orderCounts, $queuedJobs),
        ];
    }

    /**
     * @param  array{pending: int, uploading: int, failed: int}  $orderCounts
     * @param  Collection<string, int>  $queuedJobs
     * @return 'signed_out'|'syncing'|'offline'|'error'|'pending'|'synced'
     */
    public function status(?array $cashier, string $connectionStatus, array $orderCounts, Collection $queuedJobs): string
    {
        return match (true) {
            ! $cashier => 'signed_out',
            $queuedJobs->sum() > 0 || $orderCounts['uploading'] > 0 => 'syncing',
            $connectionStatus !== 'online' => 'offline',
            $orderCounts['failed'] > 0 => 'error',
            $orderCounts['pending'] > 0 => 'pending',
            default => 'synced',
        };
    }
}
