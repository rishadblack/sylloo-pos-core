<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Events\PullUpdateEvent;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\AddonGroup;
use Rishadblack\SyllooPosCore\Models\Brand;
use Rishadblack\SyllooPosCore\Models\CashRegister;
use Rishadblack\SyllooPosCore\Models\CashRegisterSession;
use Rishadblack\SyllooPosCore\Models\Category;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\ContactGroup;
use Rishadblack\SyllooPosCore\Models\DailyCategory;
use Rishadblack\SyllooPosCore\Models\DiningTable;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\ProductPricing;
use Rishadblack\SyllooPosCore\Models\ShippingMethod;
use Rishadblack\SyllooPosCore\Models\StockLevel;
use Rishadblack\SyllooPosCore\Models\Unit;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Models\VariantTemplate;
use Rishadblack\SyllooPosCore\Models\VariantTemplateItem;
use Rishadblack\SyllooPosCore\Models\Vat;
use Rishadblack\SyllooPosCore\Services\CashierSession;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Rishadblack\SyllooPosCore\Services\ProductSearchIndex;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;
use Rishadblack\SyllooPosCore\Support\CartPriceCache;
use Throwable;

class PullErpJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * A failed page (ERP offline, timeout, error response) is retried before the job fails.
     */
    public int $tries = 3;

    /**
     * The queue of the pull: after the sales upload (see config/nativephp.php).
     */
    public const QUEUE = 'pull';

    /**
     * Seconds to wait before each retry.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public $action;

    public $models;

    public $date;

    public $page;

    /**
     * Number of pages of this pull. {@see PullStart} runs the pages of a model as a chain, so the
     * last page only runs once every earlier page succeeded and is the one that moves the
     * model's sync date forward. Null when the job is used on its own.
     */
    public ?int $totalPages;

    /**
     * Rows asked for per page when the ERP pages with cursors.
     */
    public const CURSOR_PAGE_SIZE = 200;

    /**
     * Paging with cursors (an ERP that offers it, see {@see PullStart}): each page starts after the last row saved, and
     * queues the next page itself until the ERP says there is no more.
     */
    public bool $cursorPaging = false;

    /**
     * Where this page starts (the ERP's `cursors.<model>.next` of the page before), null for the first page.
     */
    public ?string $cursor = null;

    /**
     * When the ERP answered the first page of this pull: the model's next pull starts there, so a row changed during
     * this pull comes again.
     */
    public ?string $startedAt = null;

    /**
     * When {@see PullStart} queued this pull ({@see PullStart::timestamp()}): a full pull asked for after it means this
     * page's sync date is out of date and is not written.
     */
    public ?string $pullStartedAt = null;

    /**
     * Map of ERP model names to local Eloquent models.
     *
     * @var array<string, class-string<Model>>
     */
    public const MODEL_MAP = [
        'PaymentMethod' => PaymentMethod::class,
        'ShippingMethod' => ShippingMethod::class,
        'Brand' => Brand::class,
        'Category' => Category::class,
        'Product' => Product::class,
        'Contact' => Contact::class,
        'ContactGroup' => ContactGroup::class,
        'Vat' => Vat::class,
        'ProductPricing' => ProductPricing::class,
        // Units with their conversion, so a line can be sold in a box of 12 (sent as `OrderItem.*.unit_id`).
        'Unit' => Unit::class,
        // Stock per item in the till's warehouse (on hand, reserved, available); the ERP's `stock_changed` push asks
        // for it again.
        'StockLevel' => StockLevel::class,
        // The outlet's cash registers and their sessions, and the Daily book categories of cash in and out (shifts).
        'CashRegister' => CashRegister::class,
        'CashRegisterSession' => CashRegisterSession::class,
        'DailyCategory' => DailyCategory::class,
        // The names of product variations, for the variant picker (the variants come as products with `parent_id`).
        'VariantTemplate' => VariantTemplate::class,
        'VariantTemplateItem' => VariantTemplateItem::class,
        // A restaurant's tables (erp: docs/restaurant.md), pulled only while the switch is on (PullStart).
        'PosTable' => DiningTable::class,
        // A restaurant's add-on groups with their choices and links, one row per group (phase 7), also only while on.
        'ProductAddonGroup' => AddonGroup::class,
    ];

    /**
     * Models only a restaurant's till pulls (PullStart skips them while the switch is off).
     *
     * @var list<string>
     */
    public const RESTAURANT_MODELS = ['PosTable', 'ProductAddonGroup'];

    // Cache schema columns to reduce repeated DB calls
    protected static array $tableColumnsCache = [];

    public function __construct($action, $models, $date = null, $page = null, ?int $totalPages = null)
    {
        $this->action = $action;
        $this->models = $models;
        $this->date = $date;
        $this->page = $page;
        $this->totalPages = $totalPages;

        $this->onQueue(self::QUEUE);
    }

    /**
     * A page of one model pulled with a cursor.
     */
    public static function cursorPage(string $model, ?string $date, ?string $cursor = null, ?string $startedAt = null, int $page = 1): self
    {
        $job = new self('pull', $model, $date, $page);
        $job->cursorPaging = true;
        $job->cursor = $cursor;
        $job->startedAt = $startedAt;

        return $job;
    }

    /**
     * Mark this page as part of the pull that {@see PullStart} queued at the given time.
     */
    public function startedPullAt(?string $pullStartedAt): self
    {
        $this->pullStartedAt = $pullStartedAt;

        return $this;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Pulling needs a cashier's sign-in token. Without one the rest of the pull is cancelled,
        // so a later page can not move the sync date past pages that were never saved.
        $cashier = User::latestWithValidToken();

        if (! $cashier) {
            Log::info('ERP pull: no cashier is signed in with a valid token; the pull is cancelled');
            $this->batch()?->cancel();

            return;
        }

        $ErpApiService = new ErpApiService($cashier);

        // Normalize date
        if ($this->date && ! is_array($this->date)) {
            $this->date = ['all' => $this->date];
        } elseif (! $this->date) {
            $this->date = [];
        }

        // A failed request throws, so the queue retries the page and the chain of later pages
        // (and with it the sync date) waits instead of skipping this page's records.
        try {
            $response = $this->cursorPaging
                ? $ErpApiService->pullAfter((string) $this->models, $this->date, $this->cursor, self::CURSOR_PAGE_SIZE)
                : $ErpApiService->sync($this->action, $this->models, $this->date, $this->page);

            if (CashierSession::isExpiredReply($response)) {
                CashierSession::expire($ErpApiService->tokenUser());
                $this->batch()?->cancel();

                return;
            }

            if (! $response->successful()) {
                SyncErrorLog::recordResponse('pull', $response, "ERP pull of {$this->modelsLabel()} (page {$this->page}) failed: ");

                throw new \Exception("ERP Sync failed: {$response->status()} - {$response->body()}");
            }

            $data = $response->json('data.models') ?? [];

        } catch (\Exception $e) {
            Log::error('ERP pull: sync request failed: '.$e->getMessage(), [
                'models' => $this->models,
                'page' => $this->page,
                'date' => $this->date,
            ]);

            if (! isset($response)) {
                SyncErrorLog::record('pull', "Server offline during the pull of {$this->modelsLabel()}: ".$e->getMessage());
            }

            SendHeartbeat::dispatchDebounced('failure');

            throw $e;
        }

        foreach ($data as $modelName => $records) {
            try {
                $this->saveModelData($modelName, $records);
            } catch (\Exception $e) {
                Log::error("ERP pull: failed to save {$modelName} (page {$this->page}): ".$e->getMessage(), [
                    'exception' => $e,
                ]);

                throw $e;
            }
        }

        if ($this->cursorPaging) {
            $this->continueWithCursor($response->json('data.cursors.'.$this->models), $this->startedAt ?? $response->json('data.update_date'));

            return;
        }

        if ($this->isLastPage()) {
            $this->markModelsSynced(array_keys($data), $response->json('data.update_date'));
        }
    }

    /**
     * After a page pulled with a cursor: queue the next page (in the same batch, so the cashier's "last synced" waits
     * for it) or, after the last one, move the model's sync date to the time the pull started.
     *
     * A page that says there is more without a cursor to go on from (none, or the one it started at) throws, so the page
     * is tried again and the model is never marked synced with rows still missing.
     *
     * @param  array{next?: ?string, has_more?: bool}|null  $cursor
     */
    protected function continueWithCursor(?array $cursor, ?string $startedAt): void
    {
        $next = is_string($cursor['next'] ?? null) && $cursor['next'] !== '' ? $cursor['next'] : null;

        if (($cursor['has_more'] ?? false) === true) {
            if ($next === null || $next === $this->cursor) {
                SyncErrorLog::record('pull', "The ERP said more {$this->modelsLabel()} rows follow page {$this->page} but sent no next cursor.");

                throw new \RuntimeException("ERP pull of {$this->modelsLabel()} (page {$this->page}): has_more without a usable next cursor");
            }

            $nextPage = self::cursorPage((string) $this->models, is_array($this->date) ? ($this->date[$this->models] ?? $this->date['all'] ?? null) : $this->date, $next, $startedAt, (int) $this->page + 1)
                ->startedPullAt($this->pullStartedAt);

            $this->batch() ? $this->batch()->add([$nextPage]) : dispatch($nextPage);

            return;
        }

        $this->markModelsSynced([(string) $this->models], $startedAt);
    }

    /**
     * The pulled models as text, for the sync error log.
     */
    protected function modelsLabel(): string
    {
        return is_array($this->models) ? implode(', ', $this->models) : (string) $this->models;
    }

    /**
     * True when every page of this pull has now been saved.
     */
    protected function isLastPage(): bool
    {
        return $this->totalPages === null || $this->page === null || (int) $this->page >= $this->totalPages;
    }

    /**
     * Move the sync date of the given models forward, so the next pull only asks for newer changes.
     *
     * @param  array<int, string>  $modelNames
     */
    protected function markModelsSynced(array $modelNames, ?string $updateDate): void
    {
        $modelNames = array_values(array_filter($modelNames, fn (string $modelName) => isset(self::MODEL_MAP[$modelName])));

        if (! $updateDate || $modelNames === []) {
            return;
        }

        if (PullStart::fullPullRequestedSince($this->pullStartedAt)) {
            Log::info('ERP pull: a full pull was asked for after this pull started; its sync date is not kept', ['models' => $modelNames]);

            return;
        }

        $lastSyncedByModel = Settings::get('last_synced_by_model', []) ?? [];

        foreach ($modelNames as $modelName) {
            $lastSyncedByModel[$modelName] = $updateDate;
        }

        Settings::set('last_synced_by_model', $lastSyncedByModel);

        PullUpdateEvent::dispatch();
    }

    protected function saveModelData(string $modelName, array $records): void
    {
        if (! isset(self::MODEL_MAP[$modelName])) {
            Log::warning("No mapping found for model {$modelName}");

            return;
        }

        $modelClass = self::MODEL_MAP[$modelName];

        // A pull may change what prices the cart, deleted rows too (audit M2).
        CartPriceCache::forget();

        // Normalize single record
        if (! isset($records[0]) || ! is_array($records[0])) {
            $records = [$records];
        }

        // A row deleted on the ERP comes as {id, deleted_at} only: remove it here, never upsert it.
        [$deletedRecords, $records] = collect($records)->partition(fn ($record) => self::isDeletedRecord($record))->all();
        $this->deleteRecords($modelClass, $deletedRecords->all());
        $records = $records->values()->all();

        if ($records === []) {
            return;
        }

        // Ensure table columns are cached
        $table = (new $modelClass)->getTable();
        if (! isset(self::$tableColumnsCache[$table])) {
            self::$tableColumnsCache[$table] = Schema::getColumnListing($table);
        }
        $validColumns = self::$tableColumnsCache[$table];

        // Prepare records
        $processedRecords = [];
        foreach ($records as $record) {
            try {
                if (! isset($record['id'])) {
                    throw new \Exception('Missing ULID ID');
                }

                // Encode arrays as JSON
                foreach ($record as $key => $value) {
                    if (is_array($value)) {
                        $record[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                }

                // Keep only valid columns
                $record = array_intersect_key($record, array_flip($validColumns));

                // A row the ERP sends in full exists there: one deleted here earlier was restored.
                if (in_array('deleted_at', $validColumns, true) && ! array_key_exists('deleted_at', $record)) {
                    $record['deleted_at'] = null;
                }

                $processedRecords[] = $record;

            } catch (\Exception $e) {
                Log::warning("Skipping record for {$modelName}: ".json_encode($record).' - '.$e->getMessage());
            }
        }

        if (empty($processedRecords)) {
            Log::info("No valid records to upsert for {$modelName}");

            return;
        }

        // Determine columns to update (exclude 'id')
        $updateColumns = array_filter(array_keys($processedRecords[0]), fn ($col) => $col !== 'id');

        // Chunk and upsert
        collect($processedRecords)->chunk(500)->each(function ($chunk) use ($modelClass, $updateColumns) {
            $modelClass::upsert($chunk->toArray(), ['id'], $updateColumns);
        });

        // The search text and barcodes of the products just saved (audit M4).
        if ($modelClass === Product::class) {
            app(ProductSearchIndex::class)->rebuild(array_column($processedRecords, 'id'));
        }

        // The images are downloaded by a job of their own, so the pull is not held up by them.
        if (CacheErpImages::supports($modelClass)) {
            CacheErpImages::dispatch($modelClass, array_values(array_map(fn (array $record) => (string) $record['id'], $processedRecords)));
        }

        PullUpdateEvent::dispatch();

        Log::info('Upserted '.count($processedRecords)." records for {$modelClass}");
    }

    /**
     * True for a row the ERP deleted: it carries its id and deletion time and nothing else.
     */
    public static function isDeletedRecord(mixed $record): bool
    {
        return is_array($record)
            && filled($record['deleted_at'] ?? null)
            && isset($record['id'])
            && array_diff(array_keys($record), ['id', 'deleted_at']) === [];
    }

    /**
     * Remove the rows the ERP deleted: soft deleted when the local table keeps deleted rows,
     * otherwise deleted. A customer still on a sale that has not reached the ERP is kept.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array{id: string, deleted_at: string}>  $records
     */
    protected function deleteRecords(string $modelClass, array $records): void
    {
        $ids = collect($records)->pluck('id')->map(fn ($id) => (string) $id)->unique()->values();

        if ($modelClass === Contact::class && $ids->isNotEmpty()) {
            $usedIds = Order::withTrashed()
                ->whereNull('orders.last_sync_at')
                ->whereIn('orders.contact_id', $ids->all())
                ->pluck('contact_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $ids = $ids->diff($usedIds)->values();
        }

        if ($ids->isEmpty()) {
            return;
        }

        $table = (new $modelClass)->getTable();

        if (! isset(self::$tableColumnsCache[$table])) {
            self::$tableColumnsCache[$table] = Schema::getColumnListing($table);
        }

        $query = DB::table($table)->whereIn("{$table}.id", $ids->all());

        if (in_array('deleted_at', self::$tableColumnsCache[$table], true)) {
            $query->whereNull("{$table}.deleted_at")->update(['deleted_at' => now()]);
        } else {
            $query->delete();
        }

        PullUpdateEvent::dispatch();

        Log::info('Removed '.$ids->count()." records deleted on the ERP for {$modelClass}");
    }

    /**
     * The page still failed after its retries; the model's sync date stays where it was.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ERP pull: PullErpJob failed: '.$exception->getMessage(), [
            'models' => $this->models,
            'page' => $this->page,
            'date' => $this->date,
            'exception' => $exception,
        ]);
    }
}
