<?php

use Illuminate\Support\Facades\Queue;
use Rishadblack\SyllooPosCore\Desks\SaleDesk;
use Rishadblack\SyllooPosCore\Desks\SaleRefused;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Vat;
use Rishadblack\SyllooPosCore\Services\CashRounding;
use Rishadblack\SyllooPosCore\Services\OrderCalculator;
use Rishadblack\SyllooPosCore\Services\SaleChain;
use Rishadblack\SyllooPosCore\Services\SyncStatusService;

/*
 * The sale desk prices and saves a sale with the till's rules (moved from the Android till's T0).
 */

beforeEach(function () {
    Queue::fake();
    readyTill();
});

it('prices lines like the ERP: VAT inside the price and VAT on top', function () {
    $inclusive = tillProduct('Rice 5kg', 115.0, tillVat(15, Vat::INCLUSIVE));
    $exclusive = tillProduct('Oil 1L', 230.0, tillVat(15, Vat::EXCLUSIVE));

    $desk = SaleDesk::forWalkIn();
    $desk->add($inclusive->id);
    $desk->add($inclusive->id);
    $desk->add($exclusive->id);

    $priced = $desk->pricedLines();
    $amounts = $desk->amounts();
    $expected = app(OrderCalculator::class)->calculate(collect($priced)->map(fn (array $line) => [
        'quantity' => (float) $line['quantity'],
        'price' => $line['price'],
        'vat_type' => $line['vat_type'],
        'vat_percentage' => $line['vat_percentage'],
    ])->all(), null, 0.0);

    expect($desk->lines())->toHaveCount(2)
        ->and($desk->lines()[0]['quantity'])->toEqual(2)
        ->and($priced[0]['with_vat_subtotal'])->toEqual(230.0)
        ->and($priced[1]['price'])->toEqual(200.0)
        ->and($amounts)->toEqual($expected)
        ->and($amounts['vat_amount'])->toEqual(60.0)
        ->and($amounts['net_amount'])->toEqual(460.0)
        ->and($desk->totals()['total_with_vat_subtotal'])->toEqual(460.0);
});

it('gives the same totals as the PC till works out with OrderCalculator, VAT after discount or not', function (bool $vatAfterDiscount) {
    SyncStatusService::rememberServerHealth(['checked_at' => now()->toIso8601String(), 'server_time' => null, 'clock_skew_seconds' => null, 'outlet_ready' => true, 'terminal_status' => null, 'vat_after_discount' => $vatAfterDiscount, 'cash_rounding_step' => 0.5]);
    $lines = [
        tillProduct('Tea 7.5%', 99.99, tillVat(7.5, Vat::INCLUSIVE)),
        tillProduct('Soap 5% on top', 33.33, tillVat(5, Vat::EXCLUSIVE)),
        tillProduct('Bread', 12.25),
    ];

    $desk = SaleDesk::forWalkIn();

    foreach ([3, 2, 7] as $index => $quantity) {
        $desk->add($lines[$index]->id);
        $desk->setQuantity($desk->lines()[$index]['row_id'], $quantity);
    }

    // The PC till: WithTerminalItems::order_amounts() with no discount and no charge, then the cash rounding.
    $pc = app(OrderCalculator::class)->calculate(collect($desk->pricedLines())->map(fn (array $line) => [
        'quantity' => (float) $line['quantity'],
        'price' => $line['price'],
        'vat_type' => $line['vat_type'],
        'vat_percentage' => $line['vat_percentage'],
    ])->all(), null, 0.0);
    $totals = $desk->totals([], (string) tillCash()->id);

    expect($totals['total_subtotal'])->toEqual($pc['subtotal'])
        ->and($totals['total_vat_amount'])->toEqual($pc['vat_amount'])
        ->and($totals['total_rounding'])->toEqual(CashRounding::amountFor($pc['net_amount'], 0.5))
        ->and($totals['total_with_vat_subtotal'])->toEqual(round($pc['net_amount'] + CashRounding::amountFor($pc['net_amount'], 0.5), 2))
        ->and($totals['total_quantity'])->toBe(12);
})->with(['VAT after discount' => true, 'VAT before discount' => false]);

it('changes and removes lines', function () {
    $tea = tillProduct('Tea', 50.0);
    $desk = SaleDesk::forWalkIn();
    $desk->add($tea->id);
    $row = $desk->lines()[0]['row_id'];

    $desk->setQuantity($row, 3);
    expect($desk->totals()['total_with_vat_subtotal'])->toEqual(150.0);

    $desk->change($row, -1);
    expect($desk->lines()[0]['quantity'])->toEqual(2);

    $desk->change($row, -2);
    expect($desk->isEmpty())->toBeTrue();
});

it('adds by product code and by barcode, and says why not', function () {
    $milk = tillProduct('Milk', 90.0, attributes: ['code' => 'MILK1', 'barcodes' => ['8901234567890']]);

    $desk = SaleDesk::forWalkIn();

    expect($desk->addByCode('MILK1'))->toBeNull()
        ->and($desk->addByCode('8901234567890'))->toBeNull()
        ->and($desk->lines()[0]['quantity'])->toEqual(2)
        ->and($desk->lines()[0]['product_id'])->toBe((string) $milk->id)
        ->and($desk->addByCode('nope'))->toBe('No product has the code nope.')
        ->and($desk->add('missing'))->toBe('This product is not on sale.');
});

it('takes a scanned barcode before a product code that looks the same', function () {
    $byCode = tillProduct('Coded', 10.0, attributes: ['code' => '1234']);
    $byBarcode = tillProduct('Barcoded', 20.0, attributes: ['barcodes' => ['1234']]);
    $desk = SaleDesk::forWalkIn();

    expect($desk->addScanned(' 1234 '))->toBeNull()
        ->and($desk->lines()[0]['product_id'])->toBe((string) $byBarcode->id)
        ->and($desk->addScanned('0000'))->toBe('No product has the barcode 0000. Check the item, or find it by its name.')
        ->and($byCode->id)->not->toBe($byBarcode->id);
});

it('cash rounds a cash sale with the step the heartbeat kept', function () {
    SyncStatusService::rememberServerHealth(['checked_at' => now()->toIso8601String(), 'server_time' => null, 'clock_skew_seconds' => null, 'outlet_ready' => true, 'terminal_status' => null, 'vat_after_discount' => false, 'cash_rounding_step' => 0.05]);
    $cash = tillCash();
    $card = tillCard();
    $desk = SaleDesk::forWalkIn();
    $desk->add(tillProduct('Gum', 1.02)->id);

    expect($desk->totals([], (string) $cash->id)['total_rounding'])->toEqual(-0.02)
        ->and($desk->totals([], (string) $cash->id)['total_with_vat_subtotal'])->toEqual(1.0)
        ->and($desk->totals([], (string) $card->id)['total_rounding'])->toEqual(0.0);

    $order = $desk->complete($cash, 1.0);

    expect((float) $order->rounding_amount)->toEqual(-0.02)
        ->and((float) $order->net_amount)->toEqual(1.0)
        ->and((float) $order->due_amount)->toEqual(0.0);
});

it('saves a cash sale with its lines, payment, change and place in the chain', function () {
    $cash = tillCash();
    $rice = tillProduct('Rice 5kg', 115.0, tillVat(15, Vat::INCLUSIVE));
    $desk = SaleDesk::forWalkIn();
    $desk->add($rice->id);
    $desk->add($rice->id);

    $order = $desk->complete($cash, 500.0, 'cashier-1');

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->code)->toStartWith('POS')
        ->and((string) $order->desktop_pos_id)->toBe('42')
        ->and((int) $order->type)->toBe(1)
        ->and((int) $order->status)->toBe(1)
        ->and((int) $order->payment_status)->toBe(1)
        ->and((float) $order->net_amount)->toEqual(230.0)
        ->and((float) $order->vat_amount)->toEqual(30.0)
        ->and((float) $order->subtotal)->toEqual(200.0)
        ->and((float) $order->paid_amount)->toEqual(500.0)
        ->and((float) $order->pay_change_amount)->toEqual(270.0)
        ->and($order->created_by)->toBe('cashier-1')
        ->and($order->chain_serial)->toBe(1)
        ->and($order->chain_hash)->toBe(SaleChain::hash(null, 1, $order->code, $order->net_amount, (string) $order->getRawOriginal('chain_signed_at')));

    $item = $order->orderItem()->sole();
    expect((float) $item->quantity)->toEqual(2.0)
        ->and((float) $item->amount)->toEqual(115.0)
        ->and($item->vat)->toBe('15%')
        ->and((float) $item->vat_amount)->toEqual(30.0)
        ->and((float) $item->subtotal)->toEqual(200.0);

    $payment = $order->transaction()->sole();
    expect((float) $payment->amount)->toEqual(500.0)
        ->and((string) $payment->payment_method_id)->toBe((string) $cash->id)
        ->and((int) $payment->flow)->toBe(1);

    $second = (SaleDesk::forWalkIn([['row_id' => 'r', 'product_id' => (string) $rice->id, 'quantity' => 1, 'code' => null, 'name' => 'Rice', 'unit_id' => null]]))->complete($cash, 115.0);
    expect($second->chain_serial)->toBe(2)->and($second->chain_prev_hash)->toBe($order->chain_hash);
});

it('keeps a payment\'s reference, and a card\'s last four digits only for a card', function () {
    $card = tillCard();
    $bank = PaymentMethod::query()->create(['name' => 'Bank transfer', 'kind' => 'bank', 'is_cash' => false, 'serial' => 3, 'status' => 1]);
    $tea = tillProduct('Tea', 50.0);

    $paid = function (PaymentMethod $method, ?string $ref, ?string $last4) use ($tea) {
        $desk = SaleDesk::forWalkIn();
        $desk->add($tea->id);

        return $desk->complete($method, 50.0, null, $ref, $last4)->transaction()->sole();
    };

    $byCard = $paid($card, '  SLIP-0042  ', '4242');
    $byBank = $paid($bank, 'TRX778899', '4242');
    $odd = $paid($card, '   ', '42a4');
    $long = $paid($bank, str_repeat('x', 150), null);

    expect($byCard->ref)->toBe('SLIP-0042')->and($byCard->card_last4)->toBe('4242')
        ->and($byBank->ref)->toBe('TRX778899')->and($byBank->card_last4)->toBeNull()
        ->and($odd->ref)->toBeNull()->and($odd->card_last4)->toBeNull()
        ->and(mb_strlen($long->ref))->toBe(100)
        ->and($card->isCard())->toBeTrue()->and($bank->isCard())->toBeFalse();
});

it('refuses a sale that is empty, short or a card paying more than is due, and saves nothing', function () {
    $cash = tillCash();
    $card = tillCard();

    expect(fn () => SaleDesk::forWalkIn()->complete($cash, 10.0))->toThrow(SaleRefused::class, 'Add a product first.');

    $desk = SaleDesk::forWalkIn();
    $desk->add(tillProduct('Tea', 50.0)->id);

    expect(fn () => $desk->complete($cash, 40.0))->toThrow(SaleRefused::class, 'The amount given is short by 10.00')
        ->and(fn () => $desk->complete($card, 60.0))->toThrow(SaleRefused::class, 'Card can not be more than the amount due')
        ->and(fn () => $desk->complete($cash, 0.0))->toThrow(SaleRefused::class, 'The amount must be more than zero.')
        ->and(Order::query()->count())->toBe(0);
});

it('does not sell a product with variants or serial numbers yet', function () {
    $phone = tillProduct('Phone', 10000.0, attributes: ['is_serialize' => 1]);
    $desk = SaleDesk::forWalkIn();

    expect($desk->add($phone->id))->toBe('Phone has variants or serial numbers: it can not be sold on this till yet.')
        ->and($desk->isEmpty())->toBeTrue();
});

it('refuses a sale while the till has no walk-in customer or no till number', function () {
    $desk = SaleDesk::forWalkIn();
    $desk->add(tillProduct('Tea', 50.0)->id);

    Settings::forget('desktop_pos_id');
    expect(fn () => $desk->complete(tillCash(), 50.0))->toThrow(SaleRefused::class, 'This till has no till number yet');

    Settings::set('desktop_pos_id', '42');
    Contact::query()->delete();
    $desk = SaleDesk::forWalkIn();
    $desk->add(tillProduct('Coffee', 50.0)->id);

    expect(fn () => $desk->complete(tillCash(), 50.0))->toThrow(SaleRefused::class, 'This till has no walk-in customer yet');
});

it('says the desk sentences in Bangla too', function () {
    app()->setLocale('bn');

    expect(SaleDesk::forWalkIn()->add('missing'))->toBe('এই পণ্যটি বিক্রির জন্য নেই।');
});
