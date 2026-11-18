<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Models\Transaction;
use Throwable;

/**
 * Keeps the till's database small: sales the ERP already has are removed after the number of days set on the
 * Settings page (the ERP keeps the full history). A sale that has not reached the ERP is never removed, nor the last
 * sale of the sale chain (SaleChain): the next sale continues the chain from it.
 */
class OrderRetention
{
    /**
     * Days a synced sale is kept on the till when nothing else is set. 0 keeps every sale.
     */
    public const DEFAULT_DAYS = 90;

    /**
     * Sales removed per round, so the database is never locked for long.
     */
    private const CHUNK = 200;

    public function days(): int
    {
        $days = Setting::valueOf('order_retention_days');

        return is_numeric($days) ? max(0, (int) $days) : self::DEFAULT_DAYS;
    }

    /**
     * Remove the synced sales older than the kept days, with their items and payments. Returns how many.
     */
    public function prune(): int
    {
        $days = $this->days();

        if ($days === 0) {
            return 0;
        }

        $removed = 0;

        try {
            $chainHeadId = SaleChain::headOrderId();

            do {
                $orderIds = Order::withTrashed()
                    ->notDraft()
                    ->whereNotNull('orders.last_sync_at')
                    ->where('orders.created_at', '<', now()->subDays($days))
                    ->when($chainHeadId, fn ($query) => $query->whereKeyNot($chainHeadId))
                    ->limit(self::CHUNK)
                    ->pluck('orders.id')
                    ->all();

                if ($orderIds === []) {
                    break;
                }

                DB::transaction(function () use ($orderIds) {
                    OrderItem::withTrashed()->whereIn('order_items.order_id', $orderIds)->forceDelete();
                    Transaction::withTrashed()->whereIn('transactions.order_id', $orderIds)->forceDelete();
                    Order::withTrashed()->whereIn('orders.id', $orderIds)->forceDelete();
                });

                $removed += count($orderIds);
            } while (count($orderIds) === self::CHUNK);
        } catch (Throwable $exception) {
            Log::warning('Order retention: could not remove old synced sales: '.$exception->getMessage());
        }

        if ($removed > 0) {
            Log::info("Order retention: removed {$removed} synced sales older than {$days} days");
        }

        return $removed;
    }
}
