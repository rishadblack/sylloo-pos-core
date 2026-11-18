<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\Transaction;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\SerialNumbers;
use Throwable;

/**
 * Sale returns (audit Phase 3, owner decision): against a receipt of this till (offline too), what comes back is
 * refunded at what the customer paid for it, to a method the sale was paid with or in cash. Without a receipt a
 * manager approves (WithTerminalReturn) and the refund is cash or store credit. A return is an order of type 3 linked to
 * its sale and lines, refunded with money out, pushed like a sale (erp: ReceivePosOrder, PosReturnCredit).
 *
 * The refund of a return against a receipt never goes beyond the money the sale kept (bug audit R1): what comes back
 * first takes off what the customer still owes, each method gets back at most what it paid (the rest in cash), the
 * part paid from store credit goes back to store credit (R3), and cash is rounded like the sale was (R8).
 */
class SaleReturns
{
    public const TYPE = 3;

    /** @var list<string> */
    public const REASONS = ['Damaged or faulty', 'Wrong item', 'Customer changed their mind', 'Not as described', 'Other'];

    /**
     * A completed sale of this till by its receipt number (the barcode on the receipt) or id.
     */
    public function findSale(string $code, bool $askOffice = false): ?Order
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $sale = Order::query()->where('type', 1)->notDraft()
            ->where(fn ($query) => $query->where('code', $code)->orWhere('id', Str::lower($code)))
            ->with(['orderItem.product', 'transaction.paymentMethod', 'contact'])
            ->first();

        return $sale ?? ($askOffice ? $this->saleFromOffice($code) : null);
    }

    /**
     * A sale of another till of this outlet, from the office (online only): kept here as a read-only copy
     * (`from_office`: never pushed, not one of this till's sales) to make the return against it. Null when the office
     * has none or can not be asked.
     */
    public function saleFromOffice(string $code): ?Order
    {
        $sale = $this->officeSale($code);

        if ($sale === null) {
            return null;
        }

        DB::transaction(function () use ($sale) {
            $contactId = $this->officeContact($sale['contact'] ?? null);
            $order = Order::query()->find($sale['id']) ?? new Order(['id' => $sale['id']]);
            $exVat = round((float) collect($sale['items'])->sum('ex_vat_subtotal'), 2);
            $syncedHere = $this->syncedReturnsHere(collect($sale['items'])->pluck('id')->filter(fn ($id) => is_string($id))->values()->all());

            $order->forceFill([
                'id' => $sale['id'],
                'type' => 1,
                'code' => (string) ($sale['code'] ?? ''),
                'contact_id' => $contactId,
                'desktop_pos_id' => Settings::get('desktop_pos_id'),
                'order_date' => $sale['order_date'] ?? now(),
                'status' => 1,
                'subtotal' => $exVat,
                'discount_amount' => (float) ($sale['discount_amount'] ?? 0),
                'net_amount' => (float) ($sale['net_amount'] ?? 0),
                'from_office' => true,
                'last_sync_at' => now(),
                'sync_status' => 'from_office',
            ])->save();

            // Fetched again (R2): the lines keep their ids, so the old copy goes for good.
            $order->orderItem()->withTrashed()->forceDelete();
            $order->transaction()->withTrashed()->forceDelete();

            foreach ($sale['items'] as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                $order->orderItem()->create([
                    'id' => $item['id'],
                    'contact_id' => $contactId,
                    'product_id' => $item['product_id'] ?? null,
                    'name' => $item['name'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'unit_id' => $item['unit_id'] ?? null,
                    'amount' => (float) ($item['amount'] ?? 0),
                    'subtotal' => (float) ($item['ex_vat_subtotal'] ?? 0),
                    'vat' => $item['vat'] ?? null,
                    'vat_amount' => (float) ($item['vat_amount'] ?? 0),
                    'serial_tracking' => array_values(array_filter((array) ($item['serials'] ?? []), 'is_string')) ?: null,
                    'returned_elsewhere' => $this->returnedElsewhere($item['returned'] ?? 0, $syncedHere[$item['id']] ?? 0.0),
                ]);
            }

            foreach ((array) ($sale['payments'] ?? []) as $payment) {
                if (! is_array($payment)) {
                    continue;
                }

                $methodId = $payment['payment_method_id'] ?? null;
                // A payment without a method was paid from the customer's store credit (their advance).
                $storeCredit = $methodId === null;

                if ($storeCredit || PaymentMethod::query()->whereKey($methodId)->exists()) {
                    $order->transaction()->create([
                        'payment_method_id' => $methodId,
                        'contact_id' => $contactId,
                        'desktop_pos_id' => $order->desktop_pos_id,
                        'flow' => 1,
                        'status' => 1,
                        'amount' => (float) ($payment['amount'] ?? 0),
                        'net_amount' => (float) ($payment['amount'] ?? 0),
                        'store_credit' => $storeCredit,
                    ]);
                }
            }
        });

        return $this->findSale((string) $sale['code']);
    }

    /**
     * Ask the office again what was returned of a synced sale before it is returned (audit R2: another till may have
     * taken part of it back since). A sale of another till is fetched again as a whole; a sale of this till keeps its
     * lines and takes the office's returned quantities, less the returns of this till the office already counts.
     * Returns false when the office could not be asked (offline): the return may go on, but other tills may have
     * returned part of the sale.
     */
    public function refreshFromOffice(Order $sale): bool
    {
        if ($sale->from_office) {
            return $this->saleFromOffice((string) $sale->code) !== null;
        }

        // Not uploaded yet: no other till can find it, so nobody else returned any of it.
        if ($sale->last_sync_at === null) {
            return true;
        }

        $office = $this->officeSale((string) $sale->code);

        if ($office === null || Str::lower((string) $office['id']) !== Str::lower((string) $sale->id)) {
            return false;
        }

        $returned = collect($office['items'])->filter(fn ($item) => is_array($item) && is_string($item['id'] ?? null))->pluck('returned', 'id');
        $syncedHere = $this->syncedReturnsHere($sale->orderItem->pluck('id')->map(fn ($id) => (string) $id)->all());

        foreach ($sale->orderItem as $item) {
            if ($returned->has($item->id)) {
                $item->forceFill(['returned_elsewhere' => $this->returnedElsewhere($returned->get($item->id), $syncedHere[$item->id] ?? 0.0)])->save();
            }
        }

        return true;
    }

    /**
     * The office's copy of a sale (`sale-lookup`), or null when it has none or can not be asked.
     *
     * @return array<string, mixed>|null
     */
    protected function officeSale(string $code): ?array
    {
        $cashier = User::latestWithValidToken();

        if (! $cashier || trim($code) === '') {
            return null;
        }

        try {
            $response = (new ErpApiService($cashier))->saleLookup($code);
        } catch (Throwable) {
            return null;
        }

        $sale = $response->successful() ? $response->json('data.sale') : null;

        if (! is_array($sale) || ! is_string($sale['id'] ?? null) || ! is_array($sale['items'] ?? null)) {
            return null;
        }

        return $sale;
    }

    /**
     * How many of each sold line this till returned in returns the office already has (uploaded).
     *
     * @param  list<string>  $itemIds
     * @return array<string, float>
     */
    protected function syncedReturnsHere(array $itemIds): array
    {
        return OrderItem::query()
            ->whereIn('return_of_item_id', $itemIds)
            ->whereHas('order', fn ($query) => $query->where('type', self::TYPE)->notDraft()->whereNotNull('last_sync_at'))
            ->get(['return_of_item_id', 'quantity'])
            ->groupBy('return_of_item_id')
            ->map(fn ($rows) => (float) $rows->sum('quantity'))
            ->all();
    }

    /**
     * What other tills returned of a line: the office's count less this till's uploaded returns (counted here already).
     */
    protected function returnedElsewhere(mixed $officeReturned, float $syncedHere): float
    {
        return max(0.0, round((float) $officeReturned - $syncedHere, 6));
    }

    /**
     * The sale's customer on this till (pulled, or the walk-in customer).
     *
     * @param  array<string, mixed>|null  $contact
     */
    protected function officeContact(?array $contact): ?string
    {
        $id = is_string($contact['id'] ?? null) ? $contact['id'] : null;

        return ($id && Contact::query()->whereKey($id)->exists() ? $id : null)
            ?? (filled($contact['mobile'] ?? null) ? Contact::query()->where('mobile', $contact['mobile'])->value('id') : null)
            ?? Contact::query()->walkIn()->value('id');
    }

    /**
     * What is left to return of each line of the sale, at what refund per unit (the unit's share of the line after
     * its share of the order discount, VAT included; the cash rounding is worked out on the refund, refundPlan()), with the serials sold and not
     * returned yet, the serials returned already and whether units without a known serial are left (`typed_serials`).
     *
     * @return array<string, array{item: OrderItem, name: string, sold: float, returned: float, left: float, unit_refund: float, discount: float, serials: list<string>, returned_serials: list<string>, serial_tracked: bool, typed_serials: int}>
     */
    public function returnableLines(Order $sale): array
    {
        $items = $sale->orderItem;
        $returns = OrderItem::query()
            ->whereIn('return_of_item_id', $items->pluck('id'))
            ->whereHas('order', fn ($query) => $query->where('type', self::TYPE)->notDraft())
            ->get(['return_of_item_id', 'quantity', 'serial_tracking'])
            ->groupBy('return_of_item_id');
        $exVat = (float) $items->sum('subtotal');
        $discount = (float) $sale->discount_amount;
        $values = $items->mapWithKeys(fn (OrderItem $item) => [(string) $item->id => (float) $item->subtotal - ($exVat > 0 ? $discount * (float) $item->subtotal / $exVat : 0.0) + (float) $item->vat_amount]);
        $lines = [];

        foreach ($items as $item) {
            $quantity = (float) $item->quantity;
            $share = $exVat > 0 ? $discount * (float) $item->subtotal / $exVat : 0.0;
            $paid = $values[(string) $item->id];
            $returnedRows = $returns->get($item->id, collect());
            $returnedSerials = array_values(array_unique($returnedRows->flatMap(fn (OrderItem $row) => array_map('mb_strtolower', (array) $row->serial_tracking))->all()));
            // Returned here, and at other tills, as the office said.
            $returned = (float) $returnedRows->sum('quantity') + (float) $item->returned_elsewhere;
            $left = max(0.0, round($quantity - $returned, 6));
            $serials = array_values(array_filter((array) $item->serial_tracking, fn ($serial) => ! in_array(mb_strtolower((string) $serial), $returnedSerials, true)));
            $serialTracked = (bool) $item->product?->isSerialTracked() || $serials !== [];

            $lines[(string) $item->id] = [
                'item' => $item,
                'name' => (string) ($item->name ?: $item->product?->name),
                'sold' => $quantity,
                'returned' => $returned,
                'left' => $left,
                'unit_refund' => $quantity > 0 ? round($paid / $quantity, 6) : 0.0,
                'discount' => $share,
                'serials' => $serials,
                'returned_serials' => $returnedSerials,
                'serial_tracked' => $serialTracked,
                // Units of a serial line sold without a serial (before the item was tracked, or an office copy without
                // them): their serials are typed by hand (audit R6).
                'typed_serials' => $serialTracked ? max(0, (int) round($left) - count($serials)) : 0,
            ];
        }

        return $lines;
    }

    /**
     * The payment methods a refund of the sale may go to: the ones it was paid with, and cash.
     *
     * @return list<PaymentMethod>
     */
    public function refundMethods(?Order $sale): array
    {
        $methods = collect($sale?->transaction ?? [])->reject(fn (Transaction $transaction) => $transaction->store_credit)->map(fn (Transaction $transaction) => $transaction->paymentMethod)->filter();

        return $methods->push($this->cashMethod())->filter()->unique('id')->values()->all();
    }

    /**
     * What a return of these lines of the sale gives back, and how: the value of what comes back, the refund (what
     * the sale kept and was not refunded yet, after what the customer still owes), split into the store credit part,
     * the part to the chosen method (at most what it paid) and the cash part (rounded when the sale was).
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return array{value: float, refund: float, store_credit: float, method: float, cash: float, rounding: float, off_due: float}
     */
    public function quote(Order $sale, array $lines, ?PaymentMethod $method): array
    {
        $rows = $this->saleRows($sale, $lines, checkSerials: false);

        return $this->refundPlan($sale, round(array_sum(array_map(fn (array $row) => $row['value'], $rows)), 2), $method);
    }

    /**
     * @return array{value: float, refund: float, store_credit: float, method: float, cash: float, rounding: float, off_due: float}
     */
    protected function refundPlan(Order $sale, float $value, ?PaymentMethod $method): array
    {
        $kept = $this->keptPayments($sale);
        $returns = Order::query()->where('return_of_order_id', $sale->id)->where('type', self::TYPE)->notDraft()->get(['id', 'net_amount']);
        $refunded = Transaction::query()->whereIn('order_id', $returns->pluck('id'))->where('flow', 2)->get(['payment_method_id', 'store_credit', 'amount']);

        $keptTotal = round(array_sum(array_column($kept, 'amount')), 2);
        $refundedTotal = round((float) $refunded->sum('amount'), 2);
        $moneyLeft = max(0.0, round($keptTotal - $refundedTotal, 2));
        // What the customer still owes for what they keep comes first (a credit sale gives no money back).
        $owedAfter = max(0.0, (float) $sale->net_amount - (float) $returns->sum('net_amount') - $value);
        $refund = round(max(0.0, min($value, $keptTotal - $owedAfter - $refundedTotal)), 2);

        $creditPaid = array_sum(array_map(fn (array $payment) => $payment['store_credit'] ? $payment['amount'] : 0.0, $kept));
        $creditLeft = max(0.0, round($creditPaid - (float) $refunded->where('store_credit', true)->sum('amount'), 2));
        $otherLeft = max(0.0, round($moneyLeft - $creditLeft, 2));
        $credit = $creditLeft + $otherLeft > 0 ? min($creditLeft, round($refund * $creditLeft / ($creditLeft + $otherLeft), 2)) : 0.0;
        $rest = round($refund - $credit, 2);
        $methodPart = 0.0;

        if ($method && ! $method->isCash()) {
            $methodPaid = array_sum(array_map(fn (array $payment) => ! $payment['store_credit'] && $payment['method_id'] === (string) $method->id ? $payment['amount'] : 0.0, $kept));
            $methodRefunded = (float) $refunded->where('store_credit', false)->where('payment_method_id', $method->id)->sum('amount');
            $methodPart = round(min($rest, max(0.0, $methodPaid - $methodRefunded)), 2);
        }

        $cash = round($rest - $methodPart, 2);
        $rounding = 0.0;

        // The ERP takes the rounding of a return only when the whole refund is cash (no store credit, no other method)
        // and it is at most half a step (erp: ReceivePosOrder): the value of what comes back (VAT and its discount
        // share included) is rounded, so a full return of a rounded sale gives back what the sale kept. A cash refund
        // of a sale that was not rounded (paid by card, say) is rounded too (owner 2026-10-28). Never past the money
        // the sale has left, and not when the refund is less than the value by more than a rounding (a sale left due:
        // what comes back takes off what the customer owes).
        if ($cash > 0 && $credit <= 0 && $methodPart <= 0 && ($step = CashRounding::step()) > 0 && $value - $refund <= $step / 2 + 0.001) {
            $rounding = CashRounding::amountFor($value, $step);
            $roundedCash = round($value + $rounding, 2);

            if ($roundedCash > $moneyLeft + 0.001) {
                $rounding = 0.0;
            } else {
                $cash = $roundedCash;
            }
        }

        return [
            'value' => $value,
            'refund' => round($credit + $methodPart + $cash, 2),
            'store_credit' => $credit,
            'method' => $methodPart,
            'cash' => $cash,
            'rounding' => $rounding,
            'off_due' => round($value - $refund, 2),
        ];
    }

    /**
     * The money the sale kept by payment: what each payment took less the change, taken off the last payment first
     * (as PushErpJob::paymentWithoutChange sends it).
     *
     * @return list<array{method_id: ?string, store_credit: bool, amount: float}>
     */
    protected function keptPayments(Order $sale): array
    {
        $payments = Transaction::query()->where('order_id', $sale->id)->where('flow', 1)->orderBy('created_at')->orderBy('id')->get(['payment_method_id', 'store_credit', 'amount'])
            ->map(fn (Transaction $transaction) => ['method_id' => $transaction->payment_method_id !== null ? (string) $transaction->payment_method_id : null, 'store_credit' => (bool) $transaction->store_credit, 'amount' => (float) $transaction->amount])
            ->all();
        $change = round(max(0.0, (float) $sale->pay_change_amount), 2);

        foreach (array_reverse(array_keys($payments)) as $key) {
            if ($change <= 0) {
                break;
            }

            $deduction = min($change, $payments[$key]['amount']);
            $payments[$key]['amount'] = round($payments[$key]['amount'] - $deduction, 2);
            $change = round($change - $deduction, 2);
        }

        return $payments;
    }

    /**
     * The money a return paid out (not to store credit): what an exchange puts on the next sale.
     */
    public static function exchangeCredit(Order $return): float
    {
        return round((float) $return->transaction()->where('flow', 2)->where('store_credit', false)->sum('amount'), 2);
    }

    protected function cashMethod(): ?PaymentMethod
    {
        return PaymentMethod::query()->active()->get()->first(fn (PaymentMethod $method) => $method->isCash());
    }

    /**
     * Save a return and queue it for the ERP.
     *
     * $lines against a sale: `[item_id => ['quantity' => 1, 'serials' => [...]]]`; without a receipt:
     * `[['product_id' => ..., 'quantity' => 1, 'unit_refund' => 100.0]]`. Every return belongs to an open shift
     * (audit R8), so it shows on the X and Z reports.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     */
    public function create(?Order $sale, array $lines, PaymentMethod $refundMethod, string $reason, bool $storeCredit = false, ?string $approvedBy = null, ?Contact $customer = null, ?string $shiftId = null): Order
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Say why the items come back.');
        }

        if ($shiftId === null) {
            throw new InvalidArgumentException('Open your shift before a return.');
        }

        $rows = $sale ? $this->saleRows($sale, $lines) : $this->freeRows($lines);

        if ($rows === []) {
            throw new InvalidArgumentException('Choose what comes back.');
        }

        $contactId = $sale?->contact_id ?? $customer?->id ?? Contact::query()->walkIn()->value('id');

        if (! $contactId) {
            throw new InvalidArgumentException('The walk-in customer is not on this till yet: sync with the office first.');
        }

        if ($storeCredit && $sale === null && $customer === null) {
            throw new InvalidArgumentException('Store credit needs a known customer.');
        }

        $value = round(array_sum(array_map(fn (array $row) => $row['value'], $rows)), 2);
        $cashMethod = $refundMethod->isCash() ? $refundMethod : $this->cashMethod();

        if ($sale) {
            $plan = $this->refundPlan($sale, $value, $refundMethod);
            $payments = array_filter([
                ['method' => $cashMethod ?? $refundMethod, 'amount' => $plan['store_credit'], 'store_credit' => true],
                ['method' => $refundMethod, 'amount' => $plan['method'], 'store_credit' => false],
                ['method' => $cashMethod, 'amount' => $plan['cash'], 'store_credit' => false],
            ], fn (array $payment) => $payment['amount'] > 0);

            if (collect($payments)->contains(fn (array $payment) => $payment['method'] === null)) {
                throw new InvalidArgumentException($refundMethod->name.' paid less than the refund, and there is no cash method for the rest.');
            }
        } else {
            $plan = ['refund' => $value, 'rounding' => 0.0];
            $payments = [['method' => $refundMethod, 'amount' => $value, 'store_credit' => $storeCredit]];
        }

        return DB::transaction(function () use ($sale, $rows, $reason, $approvedBy, $contactId, $shiftId, $plan, $payments) {
            $subtotal = round(array_sum(array_map(fn (array $row) => $row['subtotal'], $rows)), 2);
            $discount = round(array_sum(array_map(fn (array $row) => $row['discount'], $rows)), 2);
            $vat = round(array_sum(array_map(fn (array $row) => $row['vat_amount'], $rows)), 2);
            // The rounding of a cash refund (refundPlan()).
            $rounding = round($plan['rounding'], 2);
            $net = round($subtotal - $discount + $vat + $rounding, 2);

            $return = new Order;
            $return->forceFill([
                'type' => self::TYPE,
                'code' => $return->generateCode(),
                'contact_id' => $contactId,
                'desktop_pos_id' => Settings::get('desktop_pos_id'),
                'order_date' => now(),
                'status' => 1,
                'payment_status' => 1,
                'delivery_status' => 1,
                'return_of_order_id' => $sale?->id,
                'refund_approved_by' => $approvedBy,
                'refund_reason' => $reason,
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'discount_amount' => $discount,
                'rounding_amount' => $rounding,
                'net_amount' => $net,
                'paid_amount' => $plan['refund'],
                // What comes back of a sale left due takes off what the customer owes instead of paying out.
                'due_amount' => max(0.0, round($net - $plan['refund'], 2)),
                'pay_change_amount' => 0,
                'shift_id' => $shiftId,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ])->save();

            foreach ($rows as $row) {
                $return->orderItem()->create([
                    'contact_id' => $contactId,
                    'product_id' => $row['product_id'],
                    'name' => $row['name'],
                    'quantity' => $row['quantity'],
                    'unit_id' => $row['unit_id'],
                    'amount' => $row['unit_refund'],
                    'vat' => $row['vat'],
                    'vat_amount' => $row['vat_amount'],
                    'subtotal' => $row['subtotal'],
                    'serial_tracking' => $row['serials'] === [] ? null : $row['serials'],
                    'return_of_item_id' => $row['return_of_item_id'],
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            foreach ($payments as $payment) {
                Transaction::query()->create([
                    'order_id' => $return->id,
                    'payment_method_id' => $payment['method']->id,
                    'contact_id' => $contactId,
                    'desktop_pos_id' => $return->desktop_pos_id,
                    'transaction_date' => now(),
                    'flow' => 2,
                    'status' => 1,
                    'amount' => round($payment['amount'], 2),
                    'net_amount' => round($payment['amount'], 2),
                    'store_credit' => $payment['store_credit'],
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            SaleChain::seal($return);
            PushErpJob::dispatch($return->id);

            return $return->fresh(['orderItem', 'transaction.paymentMethod']);
        });
    }

    /**
     * Lines of a return against a sale. A serial line needs one serial per unit, none twice (audit R7): a serial sold
     * and not returned yet, or one typed by hand for a unit sold without a serial (audit R6).
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected function saleRows(Order $sale, array $lines, bool $checkSerials = true): array
    {
        $returnable = $this->returnableLines($sale);
        $rows = [];

        foreach ($lines as $itemId => $line) {
            $quantity = round((float) ($line['quantity'] ?? 0), 6);
            $available = $returnable[(string) $itemId] ?? null;

            if ($quantity <= 0) {
                continue;
            }

            if (! $available || $quantity > $available['left'] + 0.000001) {
                throw new InvalidArgumentException('More comes back than is left to return of '.($available['name'] ?? 'an item').'.');
            }

            $item = $available['item'];
            $serials = SerialNumbers::split(implode("\n", (array) ($line['serials'] ?? [])));

            if ($checkSerials && $available['serial_tracked']) {
                $this->checkSaleSerials($available, $serials, $quantity);
            }

            $share = (float) $item->quantity > 0 ? $quantity / (float) $item->quantity : 0.0;
            $subtotal = round((float) $item->subtotal * $share, 2);
            $discount = round($available['discount'] * $share, 2);
            $vat = round((float) $item->vat_amount * $share, 2);

            $rows[] = [
                'product_id' => $item->product_id,
                'name' => $available['name'],
                'quantity' => $quantity,
                'unit_id' => $item->unit_id,
                'unit_refund' => $available['unit_refund'],
                'value' => round($subtotal - $discount + $vat, 2),
                'vat' => $item->vat,
                'vat_amount' => $vat,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'serials' => $serials,
                'return_of_item_id' => $item->id,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $available
     * @param  list<string>  $serials
     */
    protected function checkSaleSerials(array $available, array $serials, float $quantity): void
    {
        $lowered = array_map('mb_strtolower', $serials);

        if (count(array_unique($lowered)) !== count($lowered)) {
            throw new InvalidArgumentException('A serial number of '.$available['name'].' is there twice.');
        }

        $typed = array_diff($lowered, array_map('mb_strtolower', $available['serials']));

        if (count($serials) !== (int) round($quantity) || count($typed) > $available['typed_serials'] || array_intersect($typed, $available['returned_serials']) !== []) {
            throw new InvalidArgumentException('Choose the serial number of each unit of '.$available['name'].' that comes back.');
        }
    }

    /**
     * Lines of a return without a receipt: a product, how many and the refund of one.
     *
     * @param  array<int|string, array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected function freeRows(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $product = Product::query()->find($line['product_id'] ?? null);
            $quantity = round((float) ($line['quantity'] ?? 0), 6);
            $unitRefund = round((float) ($line['unit_refund'] ?? 0), 6);

            if (! $product || $quantity <= 0 || $unitRefund < 0) {
                continue;
            }

            $serials = SerialNumbers::split(implode("\n", (array) ($line['serials'] ?? [])));
            $lowered = array_map('mb_strtolower', $serials);

            if ($product->isSerialTracked() && count($serials) !== (int) round($quantity)) {
                throw new InvalidArgumentException('Enter the serial number of each unit of '.$product->name.' that comes back.');
            }

            if (count(array_unique($lowered)) !== count($lowered)) {
                throw new InvalidArgumentException('A serial number of '.$product->name.' is there twice.');
            }

            $refund = round($unitRefund * $quantity, 2);

            $rows[] = [
                'product_id' => $product->id,
                'name' => (string) $product->name,
                'quantity' => $quantity,
                'unit_id' => $product->unit_id,
                'unit_refund' => $unitRefund,
                'value' => $refund,
                'vat' => null,
                'vat_amount' => 0,
                'subtotal' => $refund,
                'discount' => 0,
                'serials' => $serials,
                'return_of_item_id' => null,
            ];
        }

        return $rows;
    }
}
