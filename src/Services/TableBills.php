<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Rishadblack\SyllooPosCore\Models\DiningTable;
use Rishadblack\SyllooPosCore\Models\KitchenTicket;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Support\RestaurantMode;
use RuntimeException;

/**
 * The open bills of a restaurant's till (erp: docs/restaurant.md). An open bill is a draft order with a table: it stays
 * on this till, is never uploaded, and becomes an ordinary sale when paid. There is no second order table and no new
 * money code; the cart prices a bill again when it is resumed, like any held order.
 */
class TableBills
{
    /**
     * The tiles of the tables panel, by area: every usable table with its open bill, and any bill whose table was
     * removed in the office (it stays open until it is paid). With the switch off only tables with a bill show.
     *
     * @return Collection<string, list<array{id: string, name: string, seats: ?int, bill: ?Order}>>
     */
    public function tiles(bool $withFreeTables = true): Collection
    {
        $bills = Order::query()->tableBill()->withCount('orderItem')->get()->keyBy('dining_table_id');
        // A ticket that did not print marks its table red (KitchenTickets).
        $failed = array_flip(KitchenTicket::query()->failed()->distinct()->pluck('order_id')->map(fn ($id) => (string) $id)->all());
        $tiles = collect();

        foreach (DiningTable::query()->usable()->get() as $table) {
            $bill = $bills->pull($table->id);

            if ($bill || $withFreeTables) {
                $tiles->push(['id' => (string) $table->id, 'name' => (string) $table->name, 'area' => (string) $table->area, 'seats' => $table->seats, 'bill' => $bill, 'kitchen_failed' => $bill && isset($failed[(string) $bill->id])]);
            }
        }

        foreach ($bills as $bill) {
            $tiles->push(['id' => (string) $bill->dining_table_id, 'name' => (string) $bill->table_name, 'area' => '', 'seats' => null, 'bill' => $bill, 'kitchen_failed' => isset($failed[(string) $bill->id])]);
        }

        return $tiles->groupBy('area')->map(fn (Collection $group) => $group->values()->all());
    }

    /**
     * The bill open on a table, if any.
     */
    public function billOn(string $tableId): ?Order
    {
        return Order::query()->tableBill()->where('orders.dining_table_id', $tableId)->first();
    }

    /**
     * Puts a bill on a free table.
     */
    public function move(Order $bill, DiningTable $table): Order
    {
        $bill->forceFill(['dining_table_id' => $table->id, 'table_name' => $table->name])->save();

        return $bill;
    }

    /**
     * Joins a bill into the bill of another table, in one transaction: its lines and payments move across and lines of
     * the same item in the same unit, without a price change or serial numbers, become one line. The source bill goes,
     * which frees its table. The caller prices the joined bill again through the cart.
     */
    public function merge(Order $from, Order $into): Order
    {
        if ($from->is($into)) {
            throw new RuntimeException('A bill can not be merged into itself.');
        }

        return DB::transaction(function () use ($from, $into) {
            $from->orderItem()->update(['order_id' => $into->id]);
            $from->transaction()->update(['order_id' => $into->id]);
            $this->joinLines($into);

            $into->forceFill([
                'additional_charge' => round((float) $into->additional_charge + (float) $from->additional_charge, 2),
                'additional_charge_details' => $into->additional_charge_details ?: $from->additional_charge_details,
                'guest_count' => $into->guest_count || $from->guest_count ? (int) $into->guest_count + (int) $from->guest_count : null,
                'note' => trim($into->note.' '.$from->note) ?: null,
                'opened_at' => collect([$into->opened_at, $from->opened_at])->filter()->min(),
            ])->save();

            $from->delete();

            return $into;
        });
    }

    /**
     * A paid share of a split bill leaves it (erp: docs/restaurant.md, phase 6): its quantities come off the bill's
     * lines, what the kitchen had goes with them, the bill keeps the rest of the order discount and charge and the
     * bill group. Runs inside the share's sale transaction; a bill changed since the share was made throws, so neither
     * is saved. The bill always keeps a line: the last share is the bill itself.
     *
     * @param  array<string, float>  $taken  by bill line id
     */
    public function takeShare(Order $bill, array $taken, string $groupId, ?string $discount, ?float $charge): Order
    {
        return DB::transaction(function () use ($bill, $taken, $groupId, $discount, $charge) {
            $items = $bill->orderItem()->get()->keyBy(fn ($item) => (string) $item->id);

            foreach ($taken as $id => $quantity) {
                $item = $items->get((string) $id);

                if (! $item || (float) $quantity > (float) $item->quantity + 0.000001) {
                    throw new RuntimeException('The bill changed since this share was made. Split it again.');
                }

                if ((float) $quantity >= (float) $item->quantity - 0.000001) {
                    $item->delete();

                    continue;
                }

                $item->kitchen_sent_quantity = max(0.0, round((float) $item->kitchen_sent_quantity - min((float) $quantity, (float) $item->kitchen_sent_quantity), 6));
                $item->quantity = round((float) $item->quantity - (float) $quantity, 6);
                $item->save();
            }

            if (! $bill->orderItem()->exists()) {
                throw new RuntimeException('A share can not take the whole bill.');
            }

            $bill->forceFill(['bill_group_id' => $groupId, 'discount' => $discount, 'additional_charge' => $charge ?? 0])->save();

            return $bill;
        });
    }

    /**
     * Open tables, for the shift report and the screens: how many and what they come to.
     *
     * @return array{count: int, amount: float}
     */
    public function totals(): array
    {
        $bills = Order::query()->tableBill()->get(['net_amount']);

        return ['count' => $bills->count(), 'amount' => round((float) $bills->sum('net_amount'), 2)];
    }

    /**
     * The open tables as the heartbeat tells the office: how many, what they come to and since when the oldest is open.
     * Null on a shop's till with no bill, so its heartbeat stays as it was.
     *
     * @return array{count: int, amount: float, oldest_at: ?string}|null
     */
    public function heartbeatSummary(): ?array
    {
        $bills = Order::query()->tableBill()->get(['net_amount', 'opened_at', 'created_at']);

        if ($bills->isEmpty() && ! RestaurantMode::isOn()) {
            return null;
        }

        $oldest = $bills->map(fn (Order $bill) => $bill->opened_at ?? $bill->created_at)->filter()->min();

        return ['count' => $bills->count(), 'amount' => round((float) $bills->sum('net_amount'), 2), 'oldest_at' => $oldest?->toIso8601String()];
    }

    private function joinLines(Order $bill): void
    {
        $lines = $bill->orderItem()->get();
        // A dish with add-ons, and an add-on, stay lines of their own.
        $parents = $lines->pluck('parent_item_id')->filter()->map(fn ($id) => (string) $id)->flip();

        $lines
            ->filter(fn ($line) => empty($line->price_change) && empty($line->serial_tracking) && blank($line->parent_item_id) && ! isset($parents[(string) $line->id]))
            ->groupBy(fn ($line) => $line->product_id.'|'.$line->unit_id.'|'.$line->kitchen_note)
            ->filter(fn (Collection $lines) => $lines->count() > 1)
            ->each(function (Collection $lines) {
                $keep = $lines->shift();
                $keep->quantity = (float) $keep->quantity + (float) $lines->sum('quantity');
                // What the kitchen already has of both stays counted.
                $keep->kitchen_sent_quantity = (float) $keep->kitchen_sent_quantity + (float) $lines->sum('kitchen_sent_quantity');
                $keep->save();
                $lines->each->delete();
            });
    }
}
