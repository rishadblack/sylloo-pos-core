<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Database\Seeders\CountrySeeder;
use Rishadblack\SyllooPosCore\Database\Seeders\StateSeeder;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\Country;
use Rishadblack\SyllooPosCore\Models\State;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\CashierSession;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Rishadblack\SyllooPosCore\Services\ErpCircuitBreaker;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;
use Rishadblack\SyllooPosCore\Support\RestaurantMode;
use Throwable;

class PullStart implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Seconds a queued pull stays unique: the same pull asked again (heartbeat, socket, Sync now) is not queued twice.
     */
    public int $uniqueFor = 600;

    /**
     * Minutes a pull batch counts as running; an older unfinished one (the app was closed) no longer holds up a pull.
     */
    public const RUNNING_BATCH_MINUTES = 30;

    /**
     * Set when a pull was skipped because the ERP could not be reached: the next heartbeat that reaches it pulls.
     */
    public const SKIPPED_CACHE_KEY = 'pos.pull.skipped';

    /**
     * When the ERP last asked for a full pull (`force_pull`): a page of a pull started before it never writes its sync
     * date, so the old cursor can not undo the full pull.
     */
    public const FULL_PULL_CACHE_KEY = 'pos.pull.full_requested_at';

    public $date;

    public $models;

    /**
     * The cashier whose "last synced" time is set once the whole pull has finished.
     */
    public ?string $userId;

    /**
     * When this pull read the sync dates it asks from ({@see self::timestamp()}).
     */
    protected ?string $startedAt = null;

    /**
     * @param  bool  $fullPull  pull every row from the start, whatever sync dates are kept (`force_pull`)
     */
    public function __construct($date = null, $models = null, ?string $userId = null, public bool $fullPull = false)
    {
        $this->date = $date;
        $this->models = $models;
        $this->userId = $userId;

        $this->onQueue(PullErpJob::QUEUE);
    }

    /**
     * One queued pull per set of models and start date.
     */
    public function uniqueId(): string
    {
        return md5(json_encode([$this->models, $this->date, $this->fullPull]));
    }

    /**
     * The ERP asked for everything again (`force_pull`): the sync dates are forgotten, a pull still running is cancelled
     * (and its pages that are already busy no longer write their sync date), and a full pull is queued.
     */
    public static function requestFullPull(): void
    {
        Cache::forever(self::FULL_PULL_CACHE_KEY, self::timestamp());
        Settings::set('last_synced_by_model', []);

        DB::table('job_batches')
            ->where('job_batches.name', 'like', 'ERP pull:%')
            ->whereNull('job_batches.finished_at')
            ->whereNull('job_batches.cancelled_at')
            ->pluck('job_batches.id')
            ->each(fn (string $batchId) => Bus::findBatch($batchId)?->cancel());

        self::dispatch(fullPull: true);
    }

    /**
     * True when a full pull was asked for after a pull that started at the given time (null: not part of a pull).
     */
    public static function fullPullRequestedSince(?string $pullStartedAt): bool
    {
        $requestedAt = Cache::get(self::FULL_PULL_CACHE_KEY);

        return is_string($requestedAt) && $pullStartedAt !== null && strcmp($requestedAt, $pullStartedAt) > 0;
    }

    /**
     * The current time as the pull compares it, to the microsecond.
     */
    public static function timestamp(): string
    {
        return now()->format('Y-m-d H:i:s.u');
    }

    /**
     * Whether a pull was skipped while the ERP could not be reached and has not run since.
     */
    public static function wasSkipped(): bool
    {
        return Cache::get(self::SKIPPED_CACHE_KEY) === true;
    }

    public static function forgetSkipped(): void
    {
        Cache::forget(self::SKIPPED_CACHE_KEY);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Taken before the sync dates are read: a full pull asked for after this moment outdates this pull's pages.
        $this->startedAt = self::timestamp();

        if ($this->fullPull) {
            $this->date = [];
        } elseif ($this->date && ! is_array($this->date)) {
            $originalDate = $this->date;

            if ($this->models) {
                $models = array_map('trim', explode(',', $this->models));
                $this->date = [];
                foreach ($models as $model) {
                    $this->date[$model] = $originalDate;
                }
            } else {
                $this->date = ['all' => $originalDate];
            }
        } elseif (! $this->date) {
            $this->date = Settings::get('last_synced_by_model', []);
        }

        $this->models = self::withPricingModels($this->models);

        if (self::isRunning($this->batchName())) {
            // The pages already queued for these models ask for the same rows; a second run would only repeat them.
            Log::info('ERP pull: a pull of the same models is still running; this one is skipped', ['models' => $this->models]);

            return;
        }

        // The ERP could not be reached a moment ago: the heartbeat that finds it back starts the pull again.
        if (ErpCircuitBreaker::isOpen()) {
            Log::info('ERP pull: the ERP is not reachable; the pull waits until '.ErpCircuitBreaker::openUntil()?->toIso8601String());
            Cache::forever(self::SKIPPED_CACHE_KEY, true);
            $this->seedPlaces();

            return;
        }

        // Pulling needs a cashier's sign-in token: the one who asked for it, otherwise the latest valid one.
        $cashier = $this->cashier();

        if (! $cashier) {
            Log::info('ERP pull: no cashier is signed in with a valid token; the pull waits');
            $this->seedPlaces();

            return;
        }

        $ErpApiService = new ErpApiService($cashier);

        try {
            $response = $ErpApiService->getUpdate($this->date, $this->models);
        } catch (\Exception $exception) {
            SyncErrorLog::record('pull', 'Server offline: '.$exception->getMessage());
            Cache::forever(self::SKIPPED_CACHE_KEY, true);
            SendHeartbeat::dispatchDebounced('failure');

            throw $exception;
        }

        if ($response->successful()) {
            self::forgetSkipped();
            $this->dispatchPages($response->json('data.models', []) ?? [], $response->json('data.cursor_paging') === true);
        } elseif (CashierSession::isExpiredReply($response)) {
            CashierSession::expire($ErpApiService->tokenUser());
        } else {
            Log::error('ERP pull: get-update failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'models' => $this->models,
                'date' => $this->date,
            ]);

            SyncErrorLog::recordResponse('pull', $response, 'ERP get-update failed: ');
            SendHeartbeat::dispatchDebounced('failure');
        }

        $this->seedPlaces();
    }

    /**
     * The VAT rates, group prices and units decide what a product costs, so they are pulled with the products.
     */
    public static function withPricingModels(mixed $models): mixed
    {
        if (! is_string($models) || trim($models) === '') {
            return $models;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $models))));

        if (! in_array('Product', $names, true)) {
            return $models;
        }

        return implode(',', array_values(array_unique([...$names, 'Vat', 'ProductPricing', 'Unit'])));
    }

    /**
     * The cashier whose token the pull uses.
     */
    protected function cashier(): ?User
    {
        $requester = $this->userId ? User::find($this->userId) : null;

        return $requester?->hasValidToken() ? $requester : User::latestWithValidToken();
    }

    protected function seedPlaces(): void
    {
        if (Country::count() == 0) {
            (new CountrySeeder)->run();
        }

        if (State::count() == 0) {
            (new StateSeeder)->run();
        }
    }

    /**
     * Pull every page of every model. The pages of one model run as a chain, so a page that keeps
     * failing stops the later pages and the model's sync date stays put (see {@see PullErpJob}),
     * while the other models carry on. The cashier's "last synced" time is only set once every
     * page of every model has been saved.
     *
     * @param  array<string, array{total_page?: int}>  $models
     * @param  bool  $cursorPaging  the ERP pages with cursors (`cursor_paging` in its get-update answer)
     */
    protected function dispatchPages(array $models, bool $cursorPaging = false): void
    {
        $chains = [];
        $startedAt = $this->startedAt ?? self::timestamp();

        foreach ($models as $model => $modelData) {
            // The ERP lists every model it offers (e.g. StockSerial); this terminal only pulls the ones it keeps.
            if (! isset(PullErpJob::MODEL_MAP[$model])) {
                continue;
            }

            // A shop's till keeps no tables. Its sync date stays, so a later switch-on gets them all.
            if (in_array($model, PullErpJob::RESTAURANT_MODELS, true) && ! RestaurantMode::isOn()) {
                continue;
            }

            $syncDate = $this->date[$model] ?? null;
            $totalPages = (int) ($modelData['total_page'] ?? 0);

            if ($totalPages < 1) {
                continue;
            }

            // An ERP that pages with cursors: the first page queues the next one itself (see PullErpJob::cursorPage()).
            $chains[] = $cursorPaging
                ? [PullErpJob::cursorPage($model, $syncDate)->startedPullAt($startedAt)]
                : collect(range(1, $totalPages))
                    ->map(fn (int $page) => (new PullErpJob('pull', $model, $syncDate, $page, $totalPages))->startedPullAt($startedAt))
                    ->all();
        }

        $userId = $this->userId;

        if ($chains === []) {
            self::markUserSynced($userId);

            return;
        }

        Bus::batch($chains)
            ->name($this->batchName())
            ->allowFailures()
            ->then(static fn () => PullStart::markUserSynced($userId))
            ->dispatch();
    }

    /**
     * The name of this pull's batch: one per set of models.
     */
    protected function batchName(): string
    {
        return 'ERP pull: '.(is_string($this->models) && trim($this->models) !== '' ? $this->models : 'all');
    }

    /**
     * True while a pull batch of that name still has pages to run.
     */
    public static function isRunning(string $batchName): bool
    {
        return DB::table('job_batches')
            ->where('job_batches.name', $batchName)
            ->whereNull('job_batches.finished_at')
            ->whereNull('job_batches.cancelled_at')
            ->whereColumn('job_batches.pending_jobs', '>', 'job_batches.failed_jobs')
            ->where('job_batches.created_at', '>=', now()->subMinutes(self::RUNNING_BATCH_MINUTES)->getTimestamp())
            ->exists();
    }

    /**
     * Record that the cashier's data is up to date with the ERP.
     */
    public static function markUserSynced(?string $userId): void
    {
        if ($userId) {
            User::whereKey($userId)->update(['last_sync_at' => now()]);
        }
    }

    /**
     * The job crashed (e.g. the ERP server is unreachable).
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ERP pull: PullStart job failed: '.$exception->getMessage(), [
            'models' => $this->models,
            'date' => $this->date,
            'exception' => $exception,
        ]);
    }
}
