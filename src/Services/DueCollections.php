<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\Transaction;

/**
 * Old dues a customer pays at the till without a sale (owner decision 2026-11-10, erp: docs/README.md §8q). Kept as an
 * order of its own type (TYPE, never sent as a sale) with one payment per method, in the cashier's open shift, so it
 * counts in the shift's cash, shows in Orders with its sync state, prints its own receipt and goes to the office with
 * the push job (`due-collections`), offline too. The office settles the customer's oldest unpaid sales with it and
 * keeps the rest as their credit.
 */
class DueCollections
{
    /** `orders.type` of a due collection (this till's own; the ERP books it as customer payments). */
    public const TYPE = 9;

    /** Payments one collection may have (the office takes at most 10). */
    public const MAX_PAYMENTS = 10;

    /**
     * Only due collections.
     */
    public static function only(Builder $query): Builder
    {
        return $query->where('orders.type', self::TYPE);
    }

    /**
     * Everything but due collections (a sale, a return, an older row without a type).
     */
    public static function except(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('orders.type')->orWhere('orders.type', '!=', self::TYPE));
    }

    public static function is(?Order $order): bool
    {
        return $order !== null && (int) $order->type === self::TYPE;
    }

    /**
     * Save a collection and queue it for the office.
     *
     * @param  list<array{payment_method_id: string, amount: float|int|string, ref?: ?string, card_last4?: ?string}>  $payments
     *
     * @throws InvalidArgumentException when the collection can not be taken (no payment, an unknown method, a closed shift)
     */
    public function collect(Contact $contact, array $payments, Shift $shift, ?string $note = null): Order
    {
        $payments = array_values(array_filter($payments, fn (array $payment) => round((float) ($payment['amount'] ?? 0), 2) > 0));

        if ($payments === [] || count($payments) > self::MAX_PAYMENTS || ! $shift->isOpen() || $contact->is_walk_in) {
            throw new InvalidArgumentException('Not a payment this till can take.');
        }

        $methodIds = array_values(array_unique(array_map(fn (array $payment) => (string) $payment['payment_method_id'], $payments)));

        if (PaymentMethod::query()->whereIn('payment_methods.id', $methodIds)->count() !== count($methodIds)) {
            throw new InvalidArgumentException('Choose how the customer paid.');
        }

        $total = round(array_sum(array_map(fn (array $payment) => round((float) $payment['amount'], 2), $payments)), 2);

        $order = DB::transaction(function () use ($contact, $payments, $shift, $note, $total) {
            $order = new Order;
            $order->forceFill([
                'type' => self::TYPE,
                'code' => $order->generateCode(),
                'contact_id' => $contact->id,
                'desktop_pos_id' => Settings::get('desktop_pos_id'),
                'order_date' => now(),
                'subtotal' => $total,
                'net_amount' => $total,
                'paid_amount' => $total,
                'due_amount' => 0,
                'pay_change_amount' => 0,
                'status' => 1,
                'payment_status' => 1,
                'delivery_status' => 1,
                'shift_id' => $shift->id,
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ])->save();

            foreach ($payments as $payment) {
                $amount = round((float) $payment['amount'], 2);

                Transaction::query()->create([
                    'order_id' => $order->id,
                    'contact_id' => $contact->id,
                    'desktop_pos_id' => $order->desktop_pos_id,
                    'payment_method_id' => (string) $payment['payment_method_id'],
                    'transaction_date' => $order->order_date,
                    'flow' => 1,
                    'amount' => $amount,
                    'charge_amount' => 0,
                    'net_amount' => $amount,
                    'ref' => filled($payment['ref'] ?? null) ? mb_substr(trim((string) $payment['ref']), 0, 100) : null,
                    'card_last4' => preg_match('/^\d{4}$/', (string) ($payment['card_last4'] ?? '')) ? (string) $payment['card_last4'] : null,
                    'status' => 1,
                    'store_credit' => false,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            return $order;
        });

        AuditTrail::record('due_collected', ['amount' => $total, 'payments' => count($payments)], orderCode: $order->code);

        PushErpJob::dispatch();

        return $order;
    }

    /**
     * The collection as the office takes it (`data[<id>][DueCollection|Payment]`, erp: ReceivePosDueCollection).
     *
     * @return array{DueCollection: array<string, mixed>, Payment: list<array<string, mixed>>}
     */
    public static function forPush(Order $order): array
    {
        $shift = $order->shift_id ? Shift::query()->find($order->shift_id, ['id', 'register_status', 'cash_register_session_id']) : null;

        return [
            'DueCollection' => array_filter([
                'id' => (string) $order->id,
                'code' => (string) $order->code,
                'contact_id' => (string) $order->contact_id,
                'collected_at' => $order->order_date?->format('Y-m-d H:i:s'),
                'note' => $order->note,
                // Who took the money: the office checks their right, not that of the cashier whose token pushes it.
                'created_by' => $order->created_by ? (string) $order->created_by : null,
                // Tied to the shift's register session in the office, like the shift's sales.
                'cash_register_session_id' => $shift && filled($shift->cash_register_session_id) ? (string) $shift->cash_register_session_id : null,
            ], fn ($value) => $value !== null && $value !== ''),
            'Payment' => collect($order->transaction)->map(fn (Transaction $transaction) => array_filter([
                'payment_method_id' => (string) $transaction->payment_method_id,
                'amount' => round((float) $transaction->amount, 2),
                'ref' => $transaction->ref,
                'card_last4' => $transaction->card_last4,
            ], fn ($value) => $value !== null && $value !== ''))->values()->all(),
        ];
    }
}
