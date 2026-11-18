<?php

namespace Rishadblack\SyllooPosCore\Desks;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Actions\CompleteSale;
use Rishadblack\SyllooPosCore\Contracts\TillSettings;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\ContactGroup;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\AuditTrail;
use Rishadblack\SyllooPosCore\Services\CashRounding;
use Rishadblack\SyllooPosCore\Services\CustomerCredit;
use Rishadblack\SyllooPosCore\Services\OrderCalculator;
use Rishadblack\SyllooPosCore\Services\SaleDueDate;
use Rishadblack\SyllooPosCore\Services\SalePricing;
use Rishadblack\SyllooPosCore\Services\StaffDirectory;
use Rishadblack\SyllooPosCore\Services\StockAvailability;
use Rishadblack\SyllooPosCore\Services\UnitConversion;

/**
 * The sale in progress, as plain values: the cart and a one-method payment, priced with the till's money rules and
 * saved by Actions\CompleteSale. The first desk of the shared core (android-on-core.md step 3), moved from the Android
 * till's T0; it names no screen and no platform, so every till's sale screen can drive it (the PC till's `Terminal`
 * moves to the desks later, one at a time).
 *
 * The money parts are the PC till's, line by line, with the Livewire state turned into plain values:
 * `WithTerminalItems::addItemRow()` (a tap adds one, a known line takes one more), `item_rows_computed()` (price per
 * line: SalePricing, the unit's ratio and price factor), `order_amounts()` (OrderCalculator), `Terminal::orderTotals()`
 * and `cashRoundingOf()`, `Terminal::payAndComplete()` (only cash can be more than is due).
 *
 * A sale names the walk-in customer, or a customer of the Customer desk ({@see self::forContact()}). A customer's sale
 * may be left owing, wholly or in part, as Baki ({@see self::completeOnBaki()}, owner decision 2026-11-18): the due
 * date fills itself ({@see SaleDueDate}) and the cashier may change it; over the customer's credit limit it needs the
 * `credit_limit` right (WithCustomerCredit); a manager's PIN for a cashier without it is the Approval desk's.
 *
 * Not here yet: discounts, charges, price changes, units chosen by hand, variants, serial numbers, add-ons, scale
 * labels, held orders, split payments and the rest of the audit trail.
 *
 * A line: `row_id`, `product_id`, `quantity`, `code`, `name`, `unit_id`.
 */
class SaleDesk
{
    /**
     * Amounts below half a cent are rounding noise, not money the customer still owes (Terminal::MONEY_EPSILON).
     */
    public const MONEY_EPSILON = 0.005;

    /**
     * @param  list<array{row_id: string, product_id: string, quantity: float|int|string, code: ?string, name: ?string, unit_id: ?string}>  $lines
     */
    public function __construct(protected array $lines = [], protected ?string $contactId = null, protected ?string $contactGroupId = null) {}

    /**
     * A desk for the walk-in customer, priced by their group (else the business's default group), as the PC till starts
     * every order (WithTerminalContact::useWalkIn(), applyDefaultContactGroup()).
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function forWalkIn(array $lines = []): self
    {
        $walkIn = Contact::query()->walkIn()->first();
        $groupId = $walkIn?->getAttribute('contact_group_id') ?? ContactGroup::active()->default()->orderBy('sort')->orderBy('serial')->value('id');

        return new self($lines, $walkIn?->getKey() ? (string) $walkIn->getKey() : null, $groupId ? (string) $groupId : null);
    }

    /**
     * A desk for the given customer, priced by their group (WithTerminalContact::updatedcontactMobile()); the walk-in
     * customer when none is given or the id is no active customer of this till.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public static function forContact(?string $contactId, array $lines = []): self
    {
        $contact = blank($contactId) ? null : Contact::query()->active()->find($contactId);

        if (! $contact) {
            return self::forWalkIn($lines);
        }

        $groupId = $contact->getAttribute('contact_group_id') ?? ContactGroup::active()->default()->orderBy('sort')->orderBy('serial')->value('id');

        return new self($lines, (string) $contact->getKey(), $groupId ? (string) $groupId : null);
    }

    public function contactId(): ?string
    {
        return $this->contactId;
    }

    /**
     * Whether the sale is for the walk-in customer (or names none yet).
     */
    public function isWalkIn(): bool
    {
        return $this->creditContact() === null;
    }

    /**
     * @return list<array{row_id: string, product_id: string, quantity: float|int|string, code: ?string, name: ?string, unit_id: ?string}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function contactGroupId(): ?string
    {
        return $this->contactGroupId;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * A product tile or a scan: one more of a product on sale. Answers why not (a sentence), or null when added.
     */
    public function add(string $productId): ?string
    {
        $product = Product::active()->find($productId);

        return $product ? $this->addProduct($product) : __('till.not_on_sale');
    }

    /**
     * A scanned or typed code: its product code or any of its barcodes (Product::findByScanCode()).
     */
    public function addByCode(string $code): ?string
    {
        $product = Product::findByScanCode($code);

        return $product ? $this->addProduct($product) : __('till.no_product_with_code', ['code' => Str::limit(trim($code), 40)]);
    }

    /**
     * A code from a scanner (a typing scanner or the camera): barcode first, then product code
     * (Product::findByScannedBarcode()). An unknown code is said plainly, so the cashier checks the item.
     */
    public function addScanned(string $code): ?string
    {
        $code = trim($code);
        $product = $code === '' ? null : Product::findByScannedBarcode($code);

        return $product ? $this->addProduct($product) : __('till.scan_unknown', ['code' => Str::limit($code, 40)]);
    }

    protected function addProduct(Product $product): ?string
    {
        if ($product->getAttribute('is_has_variation') || $product->isSerialTracked()) {
            return __('till.not_sold_here', ['name' => Str::limit((string) $product->getAttribute('name'), 40)]);
        }

        $key = collect($this->lines)->search(fn (array $line) => (string) $line['product_id'] === (string) $product->id);

        if ($key !== false) {
            // One more unit; a fractional quantity (2.5) keeps its fraction.
            $this->lines[$key]['quantity'] = round((float) $this->lines[$key]['quantity'] + 1, 6);

            return null;
        }

        $this->lines[] = [
            'row_id' => (string) Str::ulid(),
            'product_id' => (string) $product->id,
            'quantity' => 1,
            'code' => $product->getAttribute('code'),
            'name' => (string) $product->getAttribute('name'),
            // The office's "Sell in" unit of the item, else its own unit.
            'unit_id' => app(UnitConversion::class)->defaultSaleUnitId($product),
        ];

        return null;
    }

    /**
     * A new quantity for a line; 0 or less removes it.
     */
    public function setQuantity(string $rowId, float $quantity): void
    {
        if ($quantity <= 0) {
            $this->remove($rowId);

            return;
        }

        foreach ($this->lines as $key => $line) {
            if ($line['row_id'] === $rowId) {
                $this->lines[$key]['quantity'] = round($quantity, 6);
            }
        }
    }

    public function change(string $rowId, float $by): void
    {
        $line = collect($this->lines)->firstWhere('row_id', $rowId);

        if ($line !== null) {
            $this->setQuantity($rowId, (float) $line['quantity'] + $by);
        }
    }

    public function remove(string $rowId): void
    {
        $this->lines = array_values(array_filter($this->lines, fn (array $line) => $line['row_id'] !== $rowId));
    }

    /**
     * The lines priced like the ERP prices the sale (WithTerminalItems::item_rows_computed() without price changes):
     * `net_sale_price` one unit with VAT, `price` as the calculation takes it, `with_vat_subtotal` the line as shown.
     *
     * @return list<array<string, mixed>>
     */
    public function pricedLines(): array
    {
        $productIds = collect($this->lines)->pluck('product_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
        $products = $productIds === [] ? collect() : Product::withTrashed()->whereIn('products.id', $productIds)->get()->keyBy('id');
        $prices = app(SalePricing::class)->prices($products, $this->contactGroupId);
        $units = app(UnitConversion::class);
        $stock = app(StockAvailability::class)->forProducts($products->values());

        return collect($this->lines)->map(function (array $item) use ($prices, $products, $units, $stock) {
            $price = $prices[$item['product_id']] ?? ['unit_with_vat' => 0.0, 'price' => 0.0, 'vat_id' => null, 'vat_type' => null, 'vat_percentage' => 0.0];
            $quantity = is_numeric($item['quantity']) ? (float) $item['quantity'] : 0.0;
            $product = $products->get($item['product_id']);
            $unitId = filled($item['unit_id'] ?? null) ? (string) $item['unit_id'] : ($product?->unit_id ? (string) $product->unit_id : null);
            $ratio = $product ? $units->ratio($product, $unitId) : 1.0;

            // A unit that no longer converts (changed at the office since) falls back to the item's own unit.
            if ($ratio === null) {
                $unitId = $product->unit_id ? (string) $product->unit_id : null;
                $ratio = 1.0;
            }

            $isOtherUnit = $product && $unitId !== null && $unitId !== (string) $product->unit_id;
            $forQuantity = SalePricing::forQuantity($price, $quantity * $ratio);
            $priceFactor = $product && $isOtherUnit ? ($units->priceFactor($product, $unitId, (float) ($price['sale_price'] ?? 0)) ?? $ratio) : $ratio;
            $unitWithVat = round($forQuantity['unit_with_vat'] * $priceFactor, 6);

            return array_merge($item, [
                'unit_id' => $unitId,
                'unit_ratio' => $ratio,
                'unit_label' => $isOtherUnit ? $units->nameOf($unitId) : '',
                'base_quantity' => round($quantity * $ratio, 6),
                'net_sale_price' => $unitWithVat,
                'price' => round($forQuantity['price'] * $priceFactor, 6),
                'vat_id' => $price['vat_id'],
                'vat_type' => $price['vat_type'],
                'vat_percentage' => $price['vat_percentage'],
                'with_vat_subtotal' => round($quantity * $unitWithVat, 2),
                'stock' => $stock[$item['product_id']] ?? null,
            ]);
        })->values()->all();
    }

    /**
     * The sale's amounts worked out exactly like the ERP ({@see OrderCalculator}); no discount and no charge on this desk yet.
     *
     * @param  list<array<string, mixed>>|null  $pricedLines
     * @return array{lines: array<int|string, array<string, mixed>>, subtotal: float, discount_amount: float, vat_amount: float, charges: float, charge_vat_amount: float, charge_vat_included: float, net_amount: float}
     */
    public function amounts(?array $pricedLines = null): array
    {
        $lines = collect($pricedLines ?? $this->pricedLines())->map(fn (array $item) => [
            'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : 0.0,
            'price' => $item['price'],
            'vat_type' => $item['vat_type'],
            'vat_percentage' => $item['vat_percentage'],
        ])->all();

        return app(OrderCalculator::class)->calculate($lines, null, 0.0);
    }

    /**
     * The order totals (Terminal::orderTotals()): the payments taken, and the method the cashier is about to pay with
     * for the cash rounding of what is still due (none with $paymentsOnly).
     *
     * A payment: `payment_method_id`, `amount`, and `charge_amount` and `store_credit` when it has them.
     *
     * @param  list<array<string, mixed>>  $payments
     * @param  list<array<string, mixed>>|null  $pricedLines  the lines of pricedLines(), when already worked out
     * @return array{total_quantity: int, total_subtotal: float, total_with_vat_subtotal: float, total_discount: float, total_additional_charge: float, total_charge_shown: float, total_vat_amount: float, total_rounding: float, total_net_price: float, total_paid: float, total_charge: float, total_due: float, total_pay_change: float}
     */
    public function totals(array $payments = [], ?string $pendingMethodId = null, bool $paymentsOnly = false, ?array $pricedLines = null): array
    {
        $pricedLines ??= $this->pricedLines();
        $itemRows = collect($pricedLines);
        $paymentRows = collect($payments);
        $amounts = $this->amounts($pricedLines);
        $total_paid = round($paymentRows->sum(fn ($row) => (float) $row['amount']), 2);
        $total_charge = round($paymentRows->sum(fn ($row) => (float) ($row['charge_amount'] ?? 0)), 2);
        $total_net_price = round($itemRows->sum(fn ($row) => (float) $row['with_vat_subtotal']), 2);

        $total_discount = $amounts['discount_amount'];
        $total_additional_charge = $amounts['charges'];
        $total_charge_shown = round($amounts['charges'] - $amounts['charge_vat_included'], 2);
        $total_rounding = $this->cashRoundingOf($amounts['net_amount'], $paymentRows, $total_paid, $paymentsOnly ? null : $pendingMethodId);
        $total_with_vat_subtotal = round($amounts['net_amount'] + $total_rounding, 2);
        $total_quantity = $itemRows->sum(fn ($row) => (int) $row['quantity']);
        $total_subtotal = $amounts['subtotal'];
        $total_vat_amount = $amounts['vat_amount'];

        $total_due = round($total_with_vat_subtotal - $total_paid, 2);

        if ($total_due < self::MONEY_EPSILON) {
            $total_due = 0.0;
        }

        $total_pay_change = round($total_paid - $total_with_vat_subtotal, 2);

        if ($total_pay_change < self::MONEY_EPSILON) {
            $total_pay_change = 0.0;
        }

        return compact(
            'total_quantity',
            'total_subtotal',
            'total_with_vat_subtotal',
            'total_discount',
            'total_additional_charge',
            'total_charge_shown',
            'total_vat_amount',
            'total_rounding',
            'total_net_price',
            'total_paid',
            'total_charge',
            'total_due',
            'total_pay_change'
        );
    }

    /**
     * The cash rounding of the total (Terminal::cashRoundingOf()): only while the business rounds, and only when every
     * payment, and the method about to pay what is still due, rounds cash and none is store credit.
     *
     * @param  Collection<int, array<string, mixed>>  $paymentRows
     */
    protected function cashRoundingOf(float $netAmount, Collection $paymentRows, float $paid, ?string $pendingMethodId): float
    {
        $step = CashRounding::step();

        if ($step <= 0 || $netAmount <= 0 || $paymentRows->contains(fn (array $row) => ! empty($row['store_credit']))) {
            return 0.0;
        }

        $rounding = CashRounding::amountFor($netAmount, $step);
        $methodIds = $paymentRows->map(fn (array $row) => (string) ($row['payment_method_id'] ?? ''));

        if (round($netAmount + $rounding - $paid, 2) > self::MONEY_EPSILON) {
            if (blank($pendingMethodId)) {
                return 0.0;
            }

            $methodIds->push((string) $pendingMethodId);
        }

        $methodIds = $methodIds->unique()->values();

        if ($methodIds->isEmpty() || $methodIds->contains('')) {
            return 0.0;
        }

        $methods = PaymentMethod::whereIn('id', $methodIds->all())->get();

        if ($methods->count() !== $methodIds->count() || ! $methods->every(fn (PaymentMethod $method) => $method->roundsCash())) {
            return 0.0;
        }

        return $rounding;
    }

    /**
     * Pay with one method and save the sale (Terminal::payAndComplete() then salesStore()): `$given` is what the
     * customer handed over; only cash may be more than is due (the change). `$cashierId` is the signed-in cashier's
     * user id (the sale's `created_by`). `$ref` is the payment's transaction ID or slip number and `$cardLast4` the
     * card's last four digits (both optional, as on the PC till; only four digits are kept, and only for a card).
     * Actions\CompleteSale saves it in one transaction; a failed save charges nothing: the exception goes up and the
     * cart stays as it was.
     *
     * @throws SaleRefused when the cart is empty, a quantity is wrong or the money does not cover the sale
     */
    public function complete(PaymentMethod $method, float $given, ?string $cashierId = null, ?string $ref = null, ?string $cardLast4 = null): Order
    {
        if ($this->isEmpty()) {
            throw new SaleRefused(__('till.cart_empty'));
        }

        // Every sale names its till (the activation keeps it), so the ERP knows where it was made.
        if (blank(app(TillSettings::class)->get('desktop_pos_id'))) {
            throw new SaleRefused(__('till.no_terminal'));
        }

        // Every till sale names a customer; a shop sale names the walk-in customer the ERP sends with the pull.
        if ($this->contactId === null) {
            throw new SaleRefused(__('till.no_walk_in'));
        }

        foreach ($this->lines as $line) {
            if (! is_numeric($line['quantity']) || (float) $line['quantity'] <= 0) {
                throw new SaleRefused(__('till.quantity_more_than_zero'));
            }
        }

        $pricedLines = $this->pricedLines();
        $totalDue = $this->totals([], (string) $method->id, pricedLines: $pricedLines)['total_due'];

        if ($given <= 0) {
            throw new SaleRefused(__('till.amount_more_than_zero'));
        }

        // Only cash can be handed back as change: a card or mobile payment is never more than is due.
        if (! $method->isCash() && round($given, 2) - $totalDue > self::MONEY_EPSILON) {
            throw new SaleRefused(__('till.only_cash_more', ['method' => $method->getAttribute('name'), 'amount' => numberFormat($totalDue, true)]));
        }

        $payments = [self::paymentRow($method, $given, $ref, $cardLast4)];

        // Only the payments taken count once saved: a sale left due is never rounded.
        $totals = $this->totals($payments, null, paymentsOnly: true, pricedLines: $pricedLines);

        // A sale left owing is Baki (completeOnBaki()): it needs a customer and a due date.
        if ($totals['total_due'] > 0) {
            throw new SaleRefused(__('till.not_enough', ['amount' => numberFormat($totals['total_due'], true)]));
        }

        return app(CompleteSale::class)->handle([
            'desktop_pos_id' => (string) app(TillSettings::class)->get('desktop_pos_id'),
            'contact_id' => $this->contactId,
            'cashier_id' => blank($cashierId) ? null : $cashierId,
            'totals' => $totals,
            'lines' => $this->saleLines($pricedLines),
            'payments' => $payments,
        ]);
    }

    /**
     * The Baki figures of the sale with `$paidNow` paid in cash now: the total, what is left owing, what the customer
     * owes before and after it, their credit limit, whether it goes over, and the due date the sale gets by itself.
     *
     * @param  list<array<string, mixed>>|null  $pricedLines
     * @return array{total: float, paid: float, due: float, owes_now: float, will_owe: float, limit: float, over_limit: bool, due_date: ?string}
     */
    public function bakiFigures(float $paidNow = 0.0, ?array $pricedLines = null): array
    {
        $total = $this->totals([], null, paymentsOnly: true, pricedLines: $pricedLines)['total_with_vat_subtotal'];
        $paid = round(max(0.0, $paidNow), 2);
        $due = round(max(0.0, $total - $paid), 2);
        $due = $due < self::MONEY_EPSILON ? 0.0 : $due;
        $contact = $this->creditContact();
        $credit = app(CustomerCredit::class);
        $owesNow = $contact ? $credit->owed($contact) : 0.0;

        return [
            'total' => round($total, 2),
            'paid' => $paid,
            'due' => $due,
            'owes_now' => $owesNow,
            'will_owe' => round($owesNow + $due, 2),
            'limit' => $contact ? $credit->limit($contact) : 0.0,
            'over_limit' => $contact !== null && $credit->wouldExceed($contact, $due),
            // The customer's credit term when the office sends one (it does not yet), else 30 days.
            'due_date' => SaleDueDate::for($due, null, now()),
        ];
    }

    /**
     * Why the sale can not be left owing this much, or null: a customer is needed (not the walk-in customer), and over
     * the customer's credit limit the cashier needs the `credit_limit` right.
     */
    public function bakiProblem(float $due, ?string $cashierId = null): ?string
    {
        $contact = $this->creditContact();

        if ($contact === null) {
            return __('till.baki_needs_customer');
        }

        $credit = app(CustomerCredit::class);

        if ($credit->wouldExceed($contact, $due) && ! $this->cashierMay('credit_limit', $cashierId)) {
            return __('till.baki_over_limit', [
                'name' => $contact->getAttribute('name'),
                'owed' => numberFormat($credit->owed($contact), true),
                'limit' => numberFormat($credit->limit($contact), true),
            ]);
        }

        return null;
    }

    /**
     * Save the sale as Baki (a due sale): `$paidNow` paid in cash now (0 for nothing), the rest owed by the customer
     * until `$dueDate` (`Y-m-d`, today or later). The cash part is a payment like {@see self::complete()}'s; a sale
     * paid in full is not Baki.
     *
     * @throws SaleRefused
     */
    public function completeOnBaki(?PaymentMethod $cash, float $paidNow, ?string $dueDate, ?string $cashierId = null): Order
    {
        if ($this->isEmpty()) {
            throw new SaleRefused(__('till.cart_empty'));
        }

        if (blank(app(TillSettings::class)->get('desktop_pos_id'))) {
            throw new SaleRefused(__('till.no_terminal'));
        }

        foreach ($this->lines as $line) {
            if (! is_numeric($line['quantity']) || (float) $line['quantity'] <= 0) {
                throw new SaleRefused(__('till.quantity_more_than_zero'));
            }
        }

        if ($paidNow < 0) {
            throw new SaleRefused(__('till.amount_not_negative'));
        }

        if ($paidNow > 0 && $cash === null) {
            throw new SaleRefused(__('till.no_cash_method'));
        }

        $pricedLines = $this->pricedLines();
        $payments = $paidNow > 0 ? [self::paymentRow($cash, $paidNow)] : [];
        $totals = $this->totals($payments, null, paymentsOnly: true, pricedLines: $pricedLines);

        if ($totals['total_due'] <= 0) {
            throw new SaleRefused(__('till.baki_nothing_owed'));
        }

        if ($problem = $this->bakiProblem($totals['total_due'], $cashierId)) {
            throw new SaleRefused($problem);
        }

        $date = self::dueDateOf($dueDate);

        if ($date === null) {
            throw new SaleRefused(__('till.baki_due_date_required', ['amount' => numberFormat($totals['total_due'], true)]));
        }

        if ($date < now()->toDateString()) {
            throw new SaleRefused(__('till.baki_due_date_past'));
        }

        /** @var Contact $contact */
        $contact = $this->creditContact();
        $credit = app(CustomerCredit::class);
        $overLimit = $credit->wouldExceed($contact, $totals['total_due']);
        $owed = $credit->owed($contact);

        $order = app(CompleteSale::class)->handle([
            'desktop_pos_id' => (string) app(TillSettings::class)->get('desktop_pos_id'),
            'contact_id' => $this->contactId,
            'cashier_id' => blank($cashierId) ? null : $cashierId,
            'totals' => $totals,
            'lines' => $this->saleLines($pricedLines),
            'payments' => $payments,
            'due_date' => $date,
        ]);

        // Over the limit by the cashier's own right: kept in the audit trail, as WithCustomerCredit does.
        if ($overLimit) {
            AuditTrail::record('credit_limit_over', [
                'due' => $totals['total_due'],
                'owed' => $owed,
                'limit' => $credit->limit($contact),
            ], orderCode: (string) $order->getAttribute('code'), userId: blank($cashierId) ? null : $cashierId);
        }

        return $order;
    }

    /**
     * A due date as `Y-m-d`, or null when it is empty or not a date.
     */
    public static function dueDateOf(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $typed));

        return checkdate($month, $day, $year) ? $typed : null;
    }

    /**
     * One payment with a method (WithTerminalPayment::payment_calculate()): the reference trimmed to 100 characters
     * (empty → null) and the card's last four digits only when they are four digits of a card payment.
     *
     * @return array<string, mixed>
     */
    protected static function paymentRow(PaymentMethod $method, float $given, ?string $ref = null, ?string $cardLast4 = null): array
    {
        $ref = trim((string) $ref);
        $cardLast4 = trim((string) $cardLast4);

        $paidAmount = round($given, 2);
        $chargeRule = $method->chargeRule();
        $chargeAmount = 0.0;

        if (is_string($chargeRule) && str_contains($chargeRule, '%')) {
            $chargeAmount = $paidAmount * ((float) str_replace('%', '', $chargeRule)) / 100;
        } elseif (is_numeric($chargeRule)) {
            $chargeAmount = (float) $chargeRule;
        }

        $netPaid = $paidAmount + $chargeAmount;

        return [
            'payment_method_id' => (string) $method->id,
            'ref' => $ref === '' ? null : mb_substr($ref, 0, 100),
            'card_last4' => $method->isCard() && preg_match('/^\d{4}$/', $cardLast4) === 1 ? $cardLast4 : null,
            'amount' => $paidAmount > 0 ? numberFormat($paidAmount) : 0,
            'charge' => $chargeRule,
            'charge_amount' => $chargeAmount > 0 ? numberFormat($chargeAmount) : 0,
            'net_amount' => $netPaid > 0 ? numberFormat($netPaid) : 0,
            'store_credit' => false,
        ];
    }

    /**
     * The sale's customer whose credit is checked: an active customer, not the walk-in customer.
     */
    protected function creditContact(): ?Contact
    {
        if ($this->contactId === null) {
            return null;
        }

        $contact = Contact::query()->find($this->contactId);

        return $contact && ! $contact->getAttribute('is_walk_in') ? $contact : null;
    }

    protected function cashierMay(string $permission, ?string $cashierId): bool
    {
        $cashier = blank($cashierId) ? null : User::query()->find($cashierId);

        return $cashier !== null && app(StaffDirectory::class)->cashierMay($permission, null, $cashier);
    }

    /**
     * The lines as Actions\CompleteSale stores them (WithTerminalItems::itemStore()).
     *
     * @param  list<array<string, mixed>>  $pricedLines
     * @return list<array<string, mixed>>
     */
    protected function saleLines(array $pricedLines): array
    {
        $amountLines = $this->amounts($pricedLines)['lines'];

        return array_map(fn (int $key, array $line) => [
            'product_id' => $line['product_id'],
            'name' => $line['name'],
            'quantity' => $line['quantity'],
            'unit_id' => $line['unit_id'] ?? null,
            // The unit price with VAT the till showed: the ERP compares it with its own price.
            'amount' => $line['net_sale_price'],
            'vat' => $line['vat_id'] ? self::percentText((float) $line['vat_percentage']) : null,
            'vat_amount' => $amountLines[$key]['vat_amount'] ?? 0,
            'subtotal' => $amountLines[$key]['ex_vat_subtotal'] ?? 0,
        ], array_keys($pricedLines), $pricedLines);
    }

    /**
     * A VAT rate as stored on a line, e.g. "7.5%" (WithTerminalItems::percentText()).
     */
    public static function percentText(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 6, '.', ''), '0'), '.').'%';
    }
}
