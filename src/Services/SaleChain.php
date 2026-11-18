<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Setting;

/**
 * This till's chain of completed sales (audit SEC-5): each completed sale gets the next number and a hash over the
 * hash of the one before, its number, code, total and time. The ERP checks each sale against it and the heartbeat's
 * last number and head (erp: Modules/Pos PosSaleChain), so a sale deleted or changed here before upload is noticed.
 * The chain id lives in the settings table: a fresh start (LocalDataReset) begins a new chain.
 */
class SaleChain
{
    public const CHAIN_ID_SETTING = 'sale_chain_id';

    /**
     * Most numbers of unsent sales the heartbeat lists.
     */
    public const MAX_UNSENT = 5000;

    public static function hash(?string $prevHash, int $serial, string $code, float|int|string $netAmount, string $signedAt): string
    {
        return hash('sha256', implode('|', [$prevHash ?? '', $serial, $code, number_format((float) $netAmount, 2, '.', ''), $signedAt]));
    }

    /**
     * This till's chain, started on first use.
     */
    public static function id(): string
    {
        $chainId = Setting::valueOf(self::CHAIN_ID_SETTING);

        if (! filled($chainId)) {
            $chainId = (string) Str::ulid();
            Setting::put(self::CHAIN_ID_SETTING, $chainId);
        }

        return $chainId;
    }

    /**
     * Give a completed sale its place in the chain. A sale already in it keeps its place. Call inside the sale's
     * transaction, after it is saved with its final code and total.
     */
    public static function seal(Order $order): void
    {
        if ($order->isDraft() || $order->chain_serial !== null) {
            return;
        }

        $chainId = self::id();
        $last = Order::withTrashed()
            ->where('orders.chain_id', $chainId)
            ->whereNotNull('orders.chain_serial')
            ->orderByDesc('orders.chain_serial')
            ->first(['orders.chain_serial', 'orders.chain_hash']);

        $serial = (int) $last?->chain_serial + 1;
        $prevHash = $last?->chain_hash;
        $signedAt = now()->format('Y-m-d H:i:s');

        $order->forceFill([
            'chain_id' => $chainId,
            'chain_serial' => $serial,
            'chain_prev_hash' => $prevHash,
            'chain_signed_at' => $signedAt,
            'chain_hash' => self::hash($prevHash, $serial, (string) $order->code, $order->net_amount, $signedAt),
        ])->save();
    }

    /**
     * The id of the last sale of this till's chain, or null before the first one. Order retention keeps it: the next sale
     * takes its number and hash from it.
     */
    public static function headOrderId(): ?string
    {
        $chainId = Setting::valueOf(self::CHAIN_ID_SETTING);

        if (! filled($chainId)) {
            return null;
        }

        $head = Order::withTrashed()
            ->where('orders.chain_id', $chainId)
            ->whereNotNull('orders.chain_serial')
            ->orderByDesc('orders.chain_serial')
            ->value('orders.id');

        return $head !== null ? (string) $head : null;
    }

    /**
     * The sale's place in the chain as the push sends it (`Order.chain`), or null for a sale from before the chain.
     *
     * @return array{id: string, serial: int, prev_hash: ?string, hash: string, signed_at: string}|null
     */
    public static function forPush(Order $order): ?array
    {
        if ($order->chain_serial === null || ! filled($order->chain_hash)) {
            return null;
        }

        return [
            'id' => (string) $order->chain_id,
            'serial' => (int) $order->chain_serial,
            'prev_hash' => $order->chain_prev_hash,
            'hash' => (string) $order->chain_hash,
            'signed_at' => (string) $order->chain_signed_at,
        ];
    }

    /**
     * What the heartbeat says about the chain: its last number, its head and the numbers not received by the ERP yet.
     *
     * @return array{id: string, last_serial: int, head: ?string, unsent: list<int>}|null
     */
    public static function forHeartbeat(): ?array
    {
        $chainId = Setting::valueOf(self::CHAIN_ID_SETTING);

        if (! filled($chainId)) {
            return null;
        }

        $last = Order::withTrashed()
            ->where('orders.chain_id', $chainId)
            ->orderByDesc('orders.chain_serial')
            ->first(['orders.chain_serial', 'orders.chain_hash']);

        return [
            'id' => (string) $chainId,
            'last_serial' => (int) $last?->chain_serial,
            'head' => $last?->chain_hash,
            'unsent' => Order::query()
                ->where('orders.chain_id', $chainId)
                ->whereNull('orders.last_sync_at')
                ->orderBy('orders.chain_serial')
                ->limit(self::MAX_UNSENT)
                ->pluck('orders.chain_serial')
                ->map(fn ($serial) => (int) $serial)
                ->all(),
        ];
    }
}
