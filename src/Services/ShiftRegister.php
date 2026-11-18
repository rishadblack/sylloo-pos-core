<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Models\AuditLog;
use Rishadblack\SyllooPosCore\Models\CashMovement;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\Transaction;
use Rishadblack\SyllooPosCore\Models\User;

/**
 * Shifts (audit Phase 3): every cashier opens their own shift with a float before selling, may put cash in or take it
 * out, sees the X figures at any time and closes with a cash count, which prints the Z report. The cash the drawer
 * should hold is the float, plus cash taken, less change given, less cash refunded, plus cash of old dues collected,
 * plus cash put in, less cash taken out. One shift is
 * open on the terminal at a time (one drawer); it is a session of the ERP's cash register for this terminal, opened
 * and closed there when the till is online (RegisterSync).
 */
class ShiftRegister
{
    /**
     * The shift the cashier has open on this terminal, or null.
     */
    public function current(?User $user = null): ?Shift
    {
        $userId = $user?->id ?? Auth::id();

        return $userId ? Shift::query()->open()->where('user_id', $userId)->latest('opened_at')->first() : null;
    }

    /**
     * The shift open on this terminal, whoever's it is (one drawer, one shift).
     */
    public function openOnTerminal(): ?Shift
    {
        return Shift::query()->open()->latest('opened_at')->first();
    }

    public function open(User $user, float $float): Shift
    {
        if ($float < 0) {
            throw new InvalidArgumentException('The float can not be negative.');
        }

        return DB::transaction(function () use ($user, $float) {
            if ($shift = $this->current($user)) {
                return $shift;
            }

            if ($this->openOnTerminal()) {
                throw new InvalidArgumentException('Another shift is still open on this terminal.');
            }

            $shift = Shift::query()->create([
                'number' => (int) Shift::query()->max('number') + 1,
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_float' => round($float, 2),
                'register_status' => RegisterSync::TO_OPEN,
            ]);

            RegisterSync::dispatch();

            return $shift;
        });
    }

    public function move(Shift $shift, string $type, float $amount, ?string $reason, ?string $approvedBy = null, ?string $categoryId = null): CashMovement
    {
        if (! in_array($type, [CashMovement::IN, CashMovement::OUT], true) || $amount <= 0 || ! $shift->isOpen()) {
            throw new InvalidArgumentException('Not a cash movement this shift can take.');
        }

        $movement = $shift->cashMovements()->create([
            'type' => $type,
            'amount' => round($amount, 2),
            'reason' => $reason !== null ? mb_substr($reason, 0, 191) : null,
            'daily_category_id' => $categoryId,
            'user_id' => Auth::id() ?? $shift->user_id,
            'approved_by' => $approvedBy,
        ]);

        RegisterSync::dispatch();

        return $movement;
    }

    /**
     * The shift's figures: what the X report shows while it is open, frozen in `totals` once it is closed.
     *
     * @return array{sales_count: int, sales_total: float, discounts: float, vat: float, due: float, payments: list<array{method: string, cash: bool, amount: float}>, refunds_count: int, refunds_total: float, refunds: list<array{method: string, cash: bool, amount: float}>, cash_refunded: float, store_credit: float, due_collections_count: int, due_collected: float, collections: list<array{method: string, cash: bool, amount: float}>, cash_collected: float, cash_received: float, change_given: float, cash_in: float, cash_out: float, opening_float: float, expected_cash: float, voids: int, cleared_orders: int, price_changes: int, drawer_opens: int, waiting_count: int, waiting_amount: float}
     */
    public function summary(Shift $shift): array
    {
        if (! $shift->isOpen() && is_array($shift->totals)) {
            return $shift->totals;
        }

        $orders = Order::query()->where('shift_id', $shift->id)->where('type', 1)->notDraft()->get(['id', 'net_amount', 'discount_amount', 'vat_amount', 'due_amount', 'pay_change_amount']);
        $returns = Order::query()->where('shift_id', $shift->id)->where('type', SaleReturns::TYPE)->notDraft()->get(['id', 'net_amount']);
        $payments = $this->byMethod($orders->pluck('id')->all());
        $refunds = $this->byMethod($returns->pluck('id')->all(), storeCredit: false);
        $storeCredit = round((float) Transaction::query()->whereIn('order_id', $returns->pluck('id'))->where('store_credit', true)->sum('amount'), 2);
        // Old dues customers paid without a sale (DueCollections): their cash is drawer cash like a sale's.
        $collections = Order::query()->where('shift_id', $shift->id)->where('type', DueCollections::TYPE)->notDraft()->get(['id', 'net_amount']);
        $collected = $collections->isEmpty() ? [] : $this->byMethod($collections->pluck('id')->all());
        $cashCollected = round(collect($collected)->where('cash', true)->sum('amount'), 2);
        $cashRefunded = round(collect($refunds)->where('cash', true)->sum('amount'), 2);

        $movements = $shift->cashMovements()->get(['type', 'amount']);
        $cashReceived = round(collect($payments)->where('cash', true)->sum('amount'), 2);
        $change = round((float) $orders->sum('pay_change_amount'), 2);
        $cashIn = round((float) $movements->where('type', CashMovement::IN)->sum('amount'), 2);
        $cashOut = round((float) $movements->where('type', CashMovement::OUT)->sum('amount'), 2);
        $audit = AuditLog::query()
            ->where('user_id', $shift->user_id)
            ->where('occurred_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, fn ($query) => $query->where('occurred_at', '<=', $shift->closed_at))
            ->selectRaw('action, COUNT(*) as total')
            ->groupBy('action')
            ->pluck('total', 'action');

        $openTables = app(TableBills::class)->totals();
        // Sales and collections the office keeps for a manager to check (erp: §8q item 5): their money is in the drawer
        // and counts in the expected cash; the office books them once checked.
        $waiting = Order::query()->where('shift_id', $shift->id)->whereIn('type', [1, DueCollections::TYPE])->notDraft()
            ->where('sync_status', Order::SYNC_STATUS_WITH_ISSUES)
            ->get(['id', 'paid_amount']);

        return [
            'sales_count' => $orders->count(),
            'sales_total' => round((float) $orders->sum('net_amount'), 2),
            'discounts' => round((float) $orders->sum('discount_amount'), 2),
            'vat' => round((float) $orders->sum('vat_amount'), 2),
            'due' => round((float) $orders->sum('due_amount'), 2),
            'payments' => $payments,
            'refunds_count' => $returns->count(),
            'refunds_total' => round((float) $returns->sum('net_amount'), 2),
            'refunds' => $refunds,
            'cash_refunded' => $cashRefunded,
            'store_credit' => $storeCredit,
            'cash_received' => $cashReceived,
            'change_given' => $change,
            'cash_in' => $cashIn,
            'cash_out' => $cashOut,
            'opening_float' => round((float) $shift->opening_float, 2),
            'due_collections_count' => $collections->count(),
            'due_collected' => round((float) $collections->sum('net_amount'), 2),
            'collections' => $collected,
            'cash_collected' => $cashCollected,
            'expected_cash' => round((float) $shift->opening_float + $cashReceived - $change - $cashRefunded + $cashCollected + $cashIn - $cashOut, 2),
            'voids' => (int) ($audit['void_line'] ?? 0),
            'cleared_orders' => (int) ($audit['clear_order'] ?? 0),
            'price_changes' => (int) ($audit['line_discount'] ?? 0) + (int) ($audit['price_override'] ?? 0),
            'drawer_opens' => (int) ($audit['drawer_open'] ?? 0),
            'waiting_count' => $waiting->count(),
            'waiting_amount' => round((float) $waiting->sum('paid_amount'), 2),
            // Bills still open when the report was made: they carry over and count in the shift they are paid in.
            ...($openTables['count'] > 0 ? ['open_tables_count' => $openTables['count'], 'open_tables_amount' => $openTables['amount']] : []),
        ];
    }

    /**
     * The payments of the given orders by method (largest first), what was handed over; store credit (paid with it,
     * or refunds kept as it) is a line of its own, or left out when asked.
     *
     * @param  list<string>  $orderIds
     * @return list<array{method: string, cash: bool, amount: float}>
     */
    protected function byMethod(array $orderIds, ?bool $storeCredit = null): array
    {
        $methods = PaymentMethod::withTrashed()->get()->keyBy('id');

        return Transaction::query()
            ->whereIn('order_id', $orderIds)
            ->when($storeCredit !== null, fn ($query) => $query->where('store_credit', $storeCredit))
            ->selectRaw('payment_method_id, store_credit, SUM(amount) as total')
            ->groupBy('payment_method_id', 'store_credit')
            ->get()
            ->map(function ($row) use ($methods) {
                if ($row->store_credit) {
                    return ['method' => 'Store credit', 'cash' => false, 'amount' => round((float) $row->total, 2)];
                }

                $method = $methods->get($row->payment_method_id);

                return ['method' => (string) ($method?->name ?? 'Other'), 'cash' => (bool) $method?->isCash(), 'amount' => round((float) $row->total, 2)];
            })
            ->groupBy(fn (array $row) => $row['method'].'|'.(int) $row['cash'])
            ->map(fn ($rows) => [...$rows->first(), 'amount' => round((float) $rows->sum('amount'), 2)])
            ->values()
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    /**
     * Close the shift with the cash counted in the drawer; its figures are frozen and it goes to the ERP.
     */
    public function close(Shift $shift, float $countedCash, ?string $note = null): Shift
    {
        if (! $shift->isOpen()) {
            return $shift;
        }

        if ($countedCash < 0) {
            throw new InvalidArgumentException('The counted cash can not be negative.');
        }

        $shift->closed_at = now();
        $totals = $this->summary($shift);

        $shift->forceFill([
            'totals' => $totals,
            'expected_cash' => $totals['expected_cash'],
            'counted_cash' => round($countedCash, 2),
            'difference' => round($countedCash - $totals['expected_cash'], 2),
            'close_note' => filled($note) ? mb_substr(trim((string) $note), 0, 191) : null,
        ])->save();

        RegisterSync::dispatch();

        return $shift;
    }
}
