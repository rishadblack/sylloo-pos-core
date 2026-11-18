<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooPosCore\Events\PushUpdateEvent;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\SyncError;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\CashierSession;
use Rishadblack\SyllooPosCore\Services\DueCollections;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Rishadblack\SyllooPosCore\Services\ErpCircuitBreaker;
use Rishadblack\SyllooPosCore\Services\SaleChain;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;
use Rishadblack\SyllooPosCore\Services\SyncStatusService;
use Rishadblack\SyllooPosCore\Support\TillQueues;
use Throwable;

class PushErpJob implements ShouldQueue
{
    use Queueable;

    /**
     * Minutes before a sale the ERP could not take because of a server problem is sent again: doubling with each
     * such answer in a row, up to {@see self::SERVER_ERROR_MAX_RETRY_MINUTES}.
     */
    public const SERVER_ERROR_RETRY_MINUTES = 5;

    public const SERVER_ERROR_MAX_RETRY_MINUTES = 60;

    /**
     * Refusals that are not about the sale: a server problem, or a setting the office still has to make (no sale return
     * type, no Daily book). The sale stays pending and goes up again after a pause that doubles, like a server problem.
     *
     * @var list<string>
     */
    public const RETRY_LATER_CODES = [
        Order::SYNC_CODE_SERVER_ERROR,
        'return_type_not_set',
        'order_type_not_set',
        'daily_book_not_set',
        'payment_type_not_set',
    ];

    /**
     * Order columns that only describe this till's upload, never sent to the ERP.
     *
     * @var list<string>
     */
    public const LOCAL_ONLY_FIELDS = [
        'last_sync_at', 'ready_for_sync_at', 'sync_response', 'sync_status', 'sync_issues', 'sync_warnings', 'sync_code',
        'sync_retry_at', 'sync_attempts', 'server_order_id',
        // Sent together as `chain` (SaleChain::forPush()).
        'chain_id', 'chain_serial', 'chain_prev_hash', 'chain_hash', 'chain_signed_at',
        // An open bill's own; the table goes up as `pos_table_id`.
        'dining_table_id', 'opened_at', 'opened_by',
    ];

    /**
     * Order columns of a restaurant's till (erp: docs/restaurant.md), left out of the upload while empty.
     *
     * @var list<string>
     */
    public const RESTAURANT_FIELDS = ['sale_mode', 'table_name', 'guest_count', 'bill_group_id'];

    /**
     * Minutes before the next try of a sale after its given number of server problems in a row.
     */
    public static function serverErrorRetryMinutes(int $attempts): int
    {
        return min(self::SERVER_ERROR_MAX_RETRY_MINUTES, self::SERVER_ERROR_RETRY_MINUTES * 2 ** max(0, $attempts - 1));
    }

    /**
     * The queue of the sales upload: its own worker queue, taken before pulls and images (see config/nativephp.php).
     */
    public const QUEUE = 'push';

    /**
     * Seconds one push may hold the upload lock; a push of a crashed worker frees it after this.
     */
    public const LOCK_SECONDS = 300;

    /**
     * Seconds a push that found the upload lock taken waits before it is queued again.
     */
    public const LOCK_WAIT_SECONDS = 15;

    /**
     * A push is tried once: the ERP being unreachable or refusing it is logged, never a failed job (failed uploads are
     * reported to the ERP's sync gate, see {@see SyncStatusService::failedUploadsCount()}).
     */
    public int $tries = 1;

    /**
     * @param  string|null  $since  with $force: resend only the orders created at or after this time
     */
    public function __construct(public ?string $orderId = null, public ?bool $force = false, public ?string $since = null)
    {
        $this->onQueue(TillQueues::name(TillQueues::PUSH));
    }

    /**
     * The order's lines as sent to the ERP: every line as stored (with the `unit_id` it was sold in), with `serials`
     * (one serial number per unit) for a line that has serial numbers.
     *
     * @return list<array<string, mixed>>
     */
    public static function itemRows(Order $Order): array
    {
        return collect($Order->orderItem)->map(function ($item) {
            // What the kitchen had of the line stays on this till; a note and an add-on's dish go up only when set.
            $row = array_filter(Arr::except($item->toArray(), ['kitchen_sent_quantity']), fn (mixed $value, string $field) => $value !== null || ! in_array($field, ['kitchen_note', 'parent_item_id'], true), ARRAY_FILTER_USE_BOTH);
            $serials = array_values(array_filter(array_map(fn ($serial) => is_scalar($serial) ? trim((string) $serial) : '', (array) $item->serial_tracking), fn (string $serial) => $serial !== ''));

            if ($serials !== []) {
                $row['serials'] = $serials;
            }

            return $row;
        })->values()->all();
    }

    /**
     * Orders the automatic push uploads: never synced, not rejected by the ERP, and either not
     * uploading or stuck in "uploading" for longer than {@see Order::SYNC_STALE_AFTER_MINUTES}
     * (the app was closed mid-push). Rejected orders are only re-sent through a manual retry.
     */
    public static function pendingQuery(): Builder
    {
        return DueCollections::except(Order::query()->notDraft()->where('orders.from_office', false))->where(fn (Builder $query) => self::wherePending($query));
    }

    /**
     * Due collections waiting for the office (DueCollections), sent to their own endpoint next to the sales.
     */
    public static function pendingCollectionsQuery(): Builder
    {
        return DueCollections::only(Order::query()->notDraft())->where(fn (Builder $query) => self::wherePending($query));
    }

    /**
     * The conditions of {@see self::pendingQuery()} without the draft filter, so a manual retry can add its order
     * next to them and every order still has to be a completed sale.
     */
    protected static function wherePending(Builder $query): Builder
    {
        return $query
            ->whereNull('orders.last_sync_at')
            ->whereNull('orders.sync_response')
            ->where(fn ($query) => $query->whereNull('orders.sync_retry_at')->orWhere('orders.sync_retry_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('orders.ready_for_sync_at')
                ->orWhere('orders.ready_for_sync_at', '<', now()->subMinutes(Order::SYNC_STALE_AFTER_MINUTES)));
    }

    /**
     * A resend asked by the ERP: the completed orders (all, or the ones created since the given time) go back to
     * pending as if they were never sent, and the normal push pages through them. The ERP answers the ones it
     * already has with `already_received`. Another till's sale copied here for a return (`from_office`) is never sent.
     */
    public static function markForResend(?string $since = null): int
    {
        return Order::query()
            ->notDraft()
            ->where('orders.from_office', false)
            ->when($since, fn (Builder $query) => $query->where('orders.created_at', '>=', $since))
            ->update([
                'last_sync_at' => null,
                'ready_for_sync_at' => null,
                'sync_response' => null,
                'sync_code' => null,
                'sync_retry_at' => null,
            ]);
    }

    public function handle(): void
    {
        // One push at a time: a second one (another trigger, a second worker) would send the same orders again.
        $lock = Cache::lock('erp-push', self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Queued again as a new job: a release() would count as a second attempt and fail the job.
            Log::info('ERP push: another push is running; this one waits');

            self::dispatch($this->orderId, $this->force, $this->since)->delay(now()->addSeconds(self::LOCK_WAIT_SECONDS));

            return;
        }

        try {
            $this->push();
            // Old dues paid at the till go up after the sales they may pay (erp: ReceivePosDueCollection).
            $this->pushDueCollections();
        } finally {
            $lock->release();
        }

        SyncStatusService::forgetFailedUploadsOnceUploaded();

        // A closed shift waits for its sales before its register closes in the ERP.
        if (RegisterSync::hasWork()) {
            RegisterSync::dispatch();
        }
    }

    protected function push(): void
    {
        if ($this->force) {
            self::markForResend($this->since);
        }

        // The ERP could not be reached a moment ago: the sales wait for the heartbeat that finds it back.
        if (ErpCircuitBreaker::isOpen()) {
            Log::info('ERP push: the ERP is not reachable; the orders stay pending until '.ErpCircuitBreaker::openUntil()?->toIso8601String());

            return;
        }

        // Pushing needs a cashier's sign-in token; without one the sales simply wait.
        $cashier = User::latestWithValidToken();

        if (! $cashier) {
            Log::info('ERP push: no cashier is signed in with a valid token; the orders stay pending');

            return;
        }

        $ErpApiService = new ErpApiService($cashier);

        // A due collection is never sent as a sale (pushDueCollections()).
        $query = DueCollections::except(Order::with(['orderItem', 'transaction', 'contact'])->notDraft()->where('orders.from_office', false));

        if ($this->orderId) {
            // A manual retry: the order goes first, even when the ERP rejected it before.
            $query->where(fn (Builder $q) => self::wherePending($q)
                ->orWhere(fn ($q) => $q->where('orders.id', $this->orderId)->whereNull('orders.last_sync_at')))
                ->orderByRaw('orders.id = ? desc', [$this->orderId]);
        } else {
            $query->where(fn (Builder $q) => self::wherePending($q));
        }

        $Orders = $query->orderBy('orders.created_at')->limit(3)->get();

        if ($Orders->isEmpty()) {
            return;
        }

        $data = [];
        foreach ($Orders as $Order) {
            $payment = self::paymentWithoutChange($Order);

            $data[$Order->id] = [
                // Contact first: the ERP creates these in order, and a new customer must exist before its order.
                'Contact' => $Order->contact,
                'Order' => $payment['Order'],
                'OrderItem' => self::itemRows($Order),
                'Transaction' => $payment['Transaction'],
            ];

            $Order->update(['ready_for_sync_at' => now()]);
        }

        Log::debug('ERP push: sending orders', ['order_ids' => array_keys($data)]);

        try {
            $response = $ErpApiService->sync('push', array_keys($data), data: $data);

        } catch (\Exception $th) {
            foreach ($Orders as $Order) {
                $Order->update(['ready_for_sync_at' => null]);
            }

            PushUpdateEvent::dispatch();

            SyncErrorLog::record('push', 'Server offline: '.$th->getMessage());
            SendHeartbeat::dispatchDebounced('failure');

            // The sales stay pending; the heartbeat that finds the ERP back pushes them.
            Log::info('ERP push: the ERP could not be reached; the orders stay pending: '.$th->getMessage());

            return;
        }

        if (self::outletNotSet($response)) {
            $this->keepPendingUntilOutletIsSet($Orders);

            return;
        }

        if (CashierSession::isExpiredReply($response)) {
            // The cashier's session ended: the sales stay pending (no ERP error on them) until a cashier signs in again.
            Order::whereKey($Orders->modelKeys())->update(['ready_for_sync_at' => null]);

            CashierSession::expire($ErpApiService->tokenUser());

            PushUpdateEvent::dispatch();

            return;
        }

        if ($response->successful()) {
            $successResponses = (array) $response->json('data.success_order_ids', []);
            $failedResponses = (array) $response->json('data.failed_order_ids', []);
            $failedCodes = (array) $response->json('data.failed_codes', []);
            $results = (array) $response->json('data.results', []);
            $serverErrors = [];

            foreach ($successResponses as $successOrderId => $successResponse) {
                $result = is_array($results[$successOrderId] ?? null) ? $results[$successOrderId] : [];
                $issues = array_values(array_filter((array) ($result['issues'] ?? []), 'is_array'));
                // What the office changed without parking the sale (e.g. `unit_replaced`): shown, never a reason to resend.
                $warnings = array_values(array_filter((array) ($result['warnings'] ?? []), fn ($warning) => is_array($warning) && is_string($warning['code'] ?? null)));

                // Kept by the ERP, also when it found something the office has to check: never a failed sale. An
                // "already received" answer carries no warnings, so the ones kept from the first answer stay.
                $keepWarnings = ($result['status'] ?? null) === 'already_received' && $warnings === [];

                Order::whereKey($successOrderId)->update(Arr::except([
                    'last_sync_at' => now(),
                    'sync_response' => $successResponse ?? null,
                    'ready_for_sync_at' => null,
                    'sync_status' => is_string($result['status'] ?? null) ? $result['status'] : ($issues === [] ? 'received' : Order::SYNC_STATUS_WITH_ISSUES),
                    'sync_issues' => $issues === [] ? null : json_encode($issues, JSON_UNESCAPED_UNICODE),
                    'sync_warnings' => $warnings === [] ? null : json_encode($warnings, JSON_UNESCAPED_UNICODE),
                    'sync_code' => null,
                    'sync_retry_at' => null,
                    'sync_attempts' => 0,
                    'server_order_id' => is_string($result['order_id'] ?? null) ? mb_substr($result['order_id'], 0, 26) : null,
                ], $keepWarnings ? ['sync_warnings'] : []));
            }

            foreach ($failedResponses as $failedOrderId => $failedResponse) {
                $code = is_string($failedCodes[$failedOrderId] ?? null) ? $failedCodes[$failedOrderId] : null;

                Log::warning("ERP push: order {$failedOrderId} was rejected by the ERP", [
                    'order_id' => $failedOrderId,
                    'code' => $code,
                    'response' => $failedResponse,
                ]);

                if (in_array($code, self::RETRY_LATER_CODES, true)) {
                    // A problem of the server or of the office's setup, not of the sale: it goes up again on its own after a pause.
                    $serverErrors[] = (string) $failedOrderId;
                    $attempts = (int) $Orders->firstWhere('id', (string) $failedOrderId)?->sync_attempts + 1;

                    Order::whereKey($failedOrderId)->update([
                        'last_sync_at' => null,
                        'sync_response' => null,
                        'ready_for_sync_at' => null,
                        'sync_code' => $code,
                        'sync_attempts' => $attempts,
                        'sync_retry_at' => now()->addMinutes(self::serverErrorRetryMinutes($attempts)),
                    ]);
                } else {
                    Order::whereKey($failedOrderId)->update([
                        'last_sync_at' => null, // ❗ stay unsynced
                        'sync_response' => $failedResponse ?? null,
                        'ready_for_sync_at' => null,
                        'sync_code' => $code,
                        'sync_retry_at' => null,
                    ]);
                }

                SyncErrorLog::record('push', SyncErrorLog::rejectionMessage($failedResponse), orderId: (string) $failedOrderId);
            }

            if ($failedResponses !== []) {
                SendHeartbeat::dispatchDebounced('failure');
            }

            if ($serverErrors !== []) {
                self::dispatch()->delay(now()->addMinutes(self::SERVER_ERROR_RETRY_MINUTES)->addSeconds(5));
            }

            $this->notifyNewlyRejected($Orders, array_diff(array_map('strval', array_keys($failedResponses)), $serverErrors));

            $handledOrderIds = array_map('strval', [...array_keys($successResponses), ...array_keys($failedResponses)]);
            $unansweredOrderIds = array_diff(array_map('strval', array_keys($data)), $handledOrderIds);

            if ($unansweredOrderIds !== []) {
                // The ERP answered without mentioning these: they go back to pending for a later push.
                Log::warning('ERP push: the ERP did not answer for some orders', ['order_ids' => array_values($unansweredOrderIds)]);

                Order::whereKey($unansweredOrderIds)->update(['ready_for_sync_at' => null]);
            }

            // 🚀 Dispatch next batch if more remain unsynced. Not when the ERP ignored the whole
            // batch, or the same orders would be re-sent in a tight loop.
            if ($handledOrderIds !== [] && self::pendingQuery()->exists()) {
                self::dispatch()->delay(now()->addSeconds(5));
            }

        } else {
            foreach ($Orders as $Order) {
                $Order->update(['ready_for_sync_at' => null]);
            }

            PushUpdateEvent::dispatch();

            SyncErrorLog::recordResponse('push', $response, 'ERP push failed: ');
            SendHeartbeat::dispatchDebounced('failure');

            // The sales stay pending for the next push (the heartbeat starts one while sales wait).
            Log::warning("ERP push: the ERP refused the push: {$response->status()}", [
                'status' => $response->status(),
                'code' => SyncErrorLog::responseCode($response),
            ]);
        }

        PushUpdateEvent::dispatch();
    }

    /**
     * Send the due collections waiting for the office (at most 20 a request) to `due-collections`, and keep each
     * answer on its order like a sale's: received (with or without problems the office checks), or refused with its
     * code; a server problem or a setting the office still has to make is tried again later.
     */
    protected function pushDueCollections(): void
    {
        if (ErpCircuitBreaker::isOpen() || ! ($cashier = User::latestWithValidToken())) {
            return;
        }

        $query = $this->orderId
            // A manual retry of a refused collection goes first.
            ? DueCollections::only(Order::query()->notDraft())
                ->where(fn (Builder $q) => self::wherePending($q)->orWhere(fn ($q) => $q->where('orders.id', $this->orderId)->whereNull('orders.last_sync_at')))
                ->orderByRaw('orders.id = ? desc', [$this->orderId])
            : self::pendingCollectionsQuery();

        $collections = $query->with('transaction')->orderBy('orders.created_at')->limit(20)->get();

        if ($collections->isEmpty()) {
            return;
        }

        $erp = new ErpApiService($cashier);
        Order::whereKey($collections->modelKeys())->update(['ready_for_sync_at' => now()]);

        try {
            $response = $erp->dueCollections($collections->mapWithKeys(fn (Order $order) => [(string) $order->id => DueCollections::forPush($order)])->all());
        } catch (Throwable $exception) {
            Order::whereKey($collections->modelKeys())->update(['ready_for_sync_at' => null]);
            SyncErrorLog::record('push', 'Server offline: '.$exception->getMessage());
            SendHeartbeat::dispatchDebounced('failure');
            PushUpdateEvent::dispatch();

            return;
        }

        if (self::outletNotSet($response)) {
            $this->keepPendingUntilOutletIsSet($collections);

            return;
        }

        if (CashierSession::isExpiredReply($response) || ! $response->successful()) {
            Order::whereKey($collections->modelKeys())->update(['ready_for_sync_at' => null]);

            if (CashierSession::isExpiredReply($response)) {
                CashierSession::expire($erp->tokenUser());
            } else {
                SyncErrorLog::recordResponse('push', $response, 'Due collections not sent: ');
                SendHeartbeat::dispatchDebounced('failure');
            }

            PushUpdateEvent::dispatch();

            return;
        }

        $results = (array) $response->json('data.results', []);
        $failed = (array) $response->json('data.failed_ids', []);
        $codes = (array) $response->json('data.failed_codes', []);
        $retryLater = false;

        foreach ($collections as $collection) {
            $id = (string) $collection->id;
            $result = is_array($results[$id] ?? null) ? $results[$id] : null;

            if ($result !== null) {
                $issues = array_values(array_filter((array) ($result['issues'] ?? []), 'is_array'));

                Order::whereKey($id)->update([
                    'last_sync_at' => now(),
                    'sync_response' => json_encode([($result['status'] ?? null) === 'already_received' ? 'Payment already received.' : 'Payment received.']),
                    'ready_for_sync_at' => null,
                    'sync_status' => is_string($result['status'] ?? null) ? $result['status'] : 'received',
                    'sync_issues' => $issues === [] ? null : json_encode($issues, JSON_UNESCAPED_UNICODE),
                    'sync_code' => null,
                    'sync_retry_at' => null,
                    'sync_attempts' => 0,
                    'server_order_id' => is_string($result['server_id'] ?? null) ? mb_substr($result['server_id'], 0, 26) : null,
                ]);

                continue;
            }

            $code = is_string($codes[$id] ?? null) ? $codes[$id] : null;

            if ($code === null || in_array($code, self::RETRY_LATER_CODES, true)) {
                // Not answered, a server problem or a setting the office still has to make: tried again later.
                $attempts = (int) $collection->sync_attempts + 1;
                $retryLater = $retryLater || $code !== null;

                Order::whereKey($id)->update(array_merge(['ready_for_sync_at' => null], $code === null ? [] : [
                    'sync_code' => $code,
                    'sync_attempts' => $attempts,
                    'sync_retry_at' => now()->addMinutes(self::serverErrorRetryMinutes($attempts)),
                ]));

                continue;
            }

            $message = $failed[$id] ?? null;

            Order::whereKey($id)->update([
                'last_sync_at' => null,
                'sync_response' => json_encode([is_string($message) && $message !== '' ? $message : 'Refused by the office.']),
                'ready_for_sync_at' => null,
                'sync_code' => $code,
                'sync_retry_at' => null,
            ]);

            SyncErrorLog::record('push', SyncErrorLog::rejectionMessage($message), orderId: $id);
        }

        if ($retryLater) {
            self::dispatch()->delay(now()->addMinutes(self::SERVER_ERROR_RETRY_MINUTES)->addSeconds(5));
        } elseif (self::pendingCollectionsQuery()->exists()) {
            self::dispatch()->delay(now()->addSeconds(5));
        }

        PushUpdateEvent::dispatch();
    }

    /**
     * The ERP refused the whole push because this terminal has no active outlet on the server.
     */
    public static function outletNotSet(Response $response): bool
    {
        return $response->status() === 409 && SyncErrorLog::responseCode($response) === 'outlet_not_set';
    }

    /**
     * The terminal keeps selling while its outlet is not set on the ERP: the orders go back to
     * pending (no ERP error is written on them) and go up once a heartbeat reports the outlet as
     * ready. Not a failed job. The problem is recorded once until a heartbeat has reported it.
     *
     * @param  EloquentCollection<int, Order>  $orders
     */
    protected function keepPendingUntilOutletIsSet(EloquentCollection $orders): void
    {
        Order::whereKey($orders->modelKeys())->update(['ready_for_sync_at' => null]);

        Log::warning('ERP push: the ERP has no outlet set for this terminal; the orders stay pending', [
            'order_ids' => $orders->modelKeys(),
        ]);

        $alreadyRecorded = SyncError::query()
            ->where('sync_errors.code', 'outlet_not_set')
            ->whereNull('sync_errors.reported_at')
            ->exists();

        if (! $alreadyRecorded) {
            SyncErrorLog::record('push', 'The ERP has no outlet set for this terminal; sales are queued until it is set.', 'outlet_not_set');
        }

        SendHeartbeat::dispatchDebounced('failure');

        PushUpdateEvent::dispatch();
    }

    /**
     * The order and its payments as sent to the ERP, the same way the ERP's own order form saves them: the order keeps
     * what was handed over (`paid_amount`) and the change given back (`pay_change_amount`); each payment books the money
     * kept (`amount`, what the ERP posts to the ledger) and the change that came out of it (`pay_change_amount`), the
     * change taken off the last payment first. The order is kept as it is on this terminal for its receipt.
     *
     * @return array{Order: array<string, mixed>, Transaction: array<int, array<string, mixed>>}
     */
    public static function paymentWithoutChange(Order $Order): array
    {
        // The serial is this terminal's own numbering; the ERP assigns its own when none is sent. The upload state is
        // this till's own too: it only makes the request bigger.
        $order = Arr::except($Order->attributesToArray(), ['serial', ...self::LOCAL_ONLY_FIELDS]);

        // Only a restaurant's till sends these; a shop's sale goes up as it always did.
        $order = array_filter($order, fn (mixed $value, string $field) => $value !== null || ! in_array($field, self::RESTAURANT_FIELDS, true), ARRAY_FILTER_USE_BOTH);

        if (filled($Order->dining_table_id)) {
            $order['pos_table_id'] = $Order->dining_table_id;
        }

        if ($chain = SaleChain::forPush($Order)) {
            $order['chain'] = $chain;
        }

        $transactions = collect($Order->transaction)
            // The card terminal's slip number is the payment's reference (the ERP keeps it for card payments).
            ->map(fn ($transaction) => [...$transaction->attributesToArray(), 'terminal_ref' => $transaction->ref, 'pay_change_amount' => 0])
            ->all();
        $change = round(max(0, (float) $Order->pay_change_amount), 2);

        foreach (array_reverse(array_keys($transactions)) as $key) {
            if ($change <= 0) {
                break;
            }

            $deduction = min($change, (float) $transactions[$key]['amount']);

            $transactions[$key]['amount'] = round((float) $transactions[$key]['amount'] - $deduction, 2);
            $transactions[$key]['net_amount'] = round((float) $transactions[$key]['net_amount'] - $deduction, 2);
            $transactions[$key]['pay_change_amount'] = round($deduction, 2);
            $change = round($change - $deduction, 2);
        }

        return ['Order' => $order, 'Transaction' => $transactions];
    }

    /**
     * Tell the cashier about orders the ERP rejected. Only on the first rejection: an order that
     * already carried an ERP error (a manual retry) is not announced again.
     *
     * @param  Collection<int, Order>  $orders  the orders as they were before this push
     * @param  array<int, int|string>  $rejectedOrderIds
     */
    protected function notifyNewlyRejected(Collection $orders, array $rejectedOrderIds): void
    {
        $rejectedOrderIds = array_map('strval', $rejectedOrderIds);

        foreach ($orders as $order) {
            if (! in_array((string) $order->id, $rejectedOrderIds, true) || $order->sync_response) {
                continue;
            }

            $rejectedOrder = $order->fresh();

            if (! $rejectedOrder) {
                continue;
            }

            try {
                app(Notifier::class)->notify('Order not accepted', "Order {$rejectedOrder->code} was not accepted by the ERP: {$rejectedOrder->syncErrorMessage()}");
            } catch (Throwable $exception) {
                Log::warning('ERP push: could not show the rejected order notification: '.$exception->getMessage());
            }
        }
    }

    /**
     * The push crashed on something unexpected (an offline ERP or an error reply never fail it); the orders stay pending
     * for the next push.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ERP push: PushErpJob failed: '.$exception->getMessage(), [
            'order_id' => $this->orderId,
            'force' => $this->force,
            'since' => $this->since,
            'exception' => $exception,
        ]);
    }
}
