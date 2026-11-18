<?php

namespace Rishadblack\SyllooPosCore\Actions;

use Illuminate\Support\Facades\DB;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Services\SaleChain;
use Rishadblack\SyllooPosCore\Support\RestaurantMode;

/**
 * Saves a completed shop sale the desk has priced and checked (Desks\SaleDesk::complete()), as the PC till's
 * `Terminal::salesStore()` does: in one transaction the order, its lines, its payments, its place in the till's sale
 * chain and its upload to the ERP (PushErpJob). A failed save rolls everything back and the exception goes up, so
 * nothing is charged and the desk keeps its cart.
 *
 * The sale, as plain values:
 * - `desktop_pos_id`, `contact_id`, `cashier_id` (the signed-in cashier; null only on a till that has none yet);
 * - `totals`: the desk's order totals (SaleDesk::totals());
 * - `lines`: `product_id`, `name`, `quantity`, `unit_id`, `amount` (one unit with VAT, as shown), `vat` (the rate as
 *   text, e.g. "7.5%", or null), `vat_amount`, `subtotal` (without VAT);
 * - `due_date` (`Y-m-d`), only for a sale left owing (Baki);
 * - `payments`: `payment_method_id`, `amount`, `charge`, `charge_amount`, `net_amount`, `ref`, `card_last4`,
 *   `store_credit`.
 *
 * The sale belongs to the cashier's open shift on this till, if one is open.
 */
class CompleteSale
{
    /**
     * @param  array{desktop_pos_id: string, contact_id: string, cashier_id: ?string, totals: array<string, float|int>, lines: list<array<string, mixed>>, payments: list<array<string, mixed>>, due_date?: ?string}  $sale
     */
    public function handle(array $sale): Order
    {
        return DB::transaction(function () use ($sale): Order {
            $order = $this->storeOrder($sale);
            $this->storeLines($order, $sale['lines']);
            $this->storePayments($order, $sale['payments'], $sale['cashier_id']);
            // Its number and hash in this till's chain of sales, so the ERP notices it if it is lost or changed.
            SaleChain::seal($order);
            PushErpJob::dispatch((string) $order->getKey());

            return $order->refresh();
        });
    }

    /**
     * The cashier's open shift on this till, or null (none open, or no cashier).
     */
    public static function openShiftId(?string $cashierId): ?string
    {
        if (blank($cashierId)) {
            return null;
        }

        $id = Shift::query()->open()->where('user_id', $cashierId)->latest('opened_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Terminal::persistOrder() for a completed shop sale.
     *
     * @param  array{desktop_pos_id: string, contact_id: string, cashier_id: ?string, totals: array<string, float|int>, lines: list<array<string, mixed>>, payments: list<array<string, mixed>>, due_date?: ?string}  $sale
     */
    protected function storeOrder(array $sale): Order
    {
        $totals = $sale['totals'];
        $positive = fn (string $key): float|int => $totals[$key] > 0 ? $totals[$key] : 0;
        $netAmount = $positive('total_with_vat_subtotal');
        $paidAmount = $positive('total_paid');

        $paymentStatus = match (true) {
            $paidAmount > 0 && $paidAmount >= $netAmount => 1,
            $paidAmount > 0 => 3,
            default => 2,
        };

        $order = new Order;
        $order->forceFill([
            'created_by' => $sale['cashier_id'],
            'created_at' => now(),
            'desktop_pos_id' => $sale['desktop_pos_id'],
            'order_date' => now(),
            'type' => 1,
            'updated_by' => $sale['cashier_id'],
        ]);

        $order->forceFill([
            'code' => $order->generateCode(),
            'contact_id' => $sale['contact_id'],
            'additional_charge' => $totals['total_additional_charge'],
            // A Baki sale's due date (SaleDesk::completeOnBaki()); none when nothing is owed.
            'due_date' => $positive('total_due') > 0 ? ($sale['due_date'] ?? null) : null,
            'subtotal' => $positive('total_subtotal'),
            'discount' => null,
            'discount_amount' => $positive('total_discount'),
            'vat_amount' => $positive('total_vat_amount'),
            'net_amount' => $netAmount,
            'rounding_amount' => $totals['total_rounding'],
            'paid_amount' => $paidAmount,
            'due_amount' => $positive('total_due'),
            'pay_change_amount' => $positive('total_pay_change'),
            'is_partial_payment' => 1,
            'shift_id' => self::openShiftId($sale['cashier_id']),
            'sale_mode' => RestaurantMode::isOn() ? 'takeaway' : null,
            'payment_status' => $paymentStatus,
            'delivery_status' => 1,
            // Paid and delivered: complete; nothing paid: due; else partly paid.
            'status' => match ($paymentStatus) {
                1 => 1,
                2 => 2,
                default => 3,
            },
        ]);

        $order->save();

        return $order;
    }

    /**
     * WithTerminalItems::itemStore().
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function storeLines(Order $order, array $lines): void
    {
        $by = $order->getAttribute('created_by');

        foreach ($lines as $line) {
            $order->orderItem()->make()->forceFill([
                'contact_id' => $order->getAttribute('contact_id'),
                'created_by' => $by,
                'updated_by' => $by,
                'product_id' => $line['product_id'],
                'name' => $line['name'],
                'quantity' => $line['quantity'],
                'unit_id' => $line['unit_id'] ?? null,
                'serial_tracking' => null,
                // The unit price with VAT the till showed: the ERP compares it with its own price.
                'amount' => $line['amount'],
                'vat' => $line['vat'] ?? null,
                'vat_amount' => $line['vat_amount'] ?? 0,
                'price_change' => null,
                'subtotal' => $line['subtotal'] ?? 0,
                'kitchen_sent_quantity' => 0,
                'kitchen_note' => null,
            ])->save();
        }
    }

    /**
     * WithTerminalPayment::paymentStore().
     *
     * @param  list<array<string, mixed>>  $payments
     */
    protected function storePayments(Order $order, array $payments, ?string $cashierId): void
    {
        foreach ($payments as $payment) {
            $order->transaction()->make()->forceFill([
                'created_by' => $cashierId,
                'updated_by' => $cashierId,
                'flow' => in_array((int) $order->getAttribute('type'), [1, 4], true) ? 1 : 2,
                'employee_id' => $order->getAttribute('employee_id'),
                'contact_id' => $order->getAttribute('contact_id'),
                'desktop_pos_id' => $order->getAttribute('desktop_pos_id'),
                'order_id' => $order->getKey(),
                'transaction_date' => $order->getAttribute('order_date'),
                'amount' => (float) ($payment['amount'] ?? 0),
                'charge' => $payment['charge'] ?? null,
                'charge_amount' => $payment['charge_amount'] ?? 0,
                'net_amount' => $payment['net_amount'],
                'status' => 1,
                'ref' => $payment['ref'] ?? null,
                'card_last4' => $payment['card_last4'] ?? null,
                'store_credit' => ! empty($payment['store_credit']),
                'payment_method_id' => $payment['payment_method_id'],
            ])->save();
        }
    }
}
