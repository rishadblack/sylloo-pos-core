<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Rishadblack\SyllooPosCore\Actions\CompleteSale;
use Rishadblack\SyllooPosCore\Desks\SaleDesk;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\Transaction;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\SaleChain;

/*
 * Actions\CompleteSale writes the sale the desk checked: one transaction, the chain sealed, the upload queued, the
 * cashier and their open shift on it; nothing is kept when any part fails, and the desk keeps its cart.
 */

beforeEach(function () {
    Queue::fake();
    readyTill();
});

function deskWithTea(): SaleDesk
{
    $desk = SaleDesk::forWalkIn();
    $desk->add(tillProduct('Tea', 50.0)->id);

    return $desk;
}

it('saves the order, its lines and payment in one transaction and queues the upload', function () {
    $cashier = User::factory()->create();
    $desk = deskWithTea();
    $cash = tillCash();
    $outside = DB::transactionLevel();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^(insert|update)/i', $query->sql)) {
            $writes[] = DB::transactionLevel();
        }
    });

    $order = $desk->complete($cash, 50.0, (string) $cashier->id);

    expect($order->created_by)->toBe((string) $cashier->id)
        ->and($order->updated_by)->toBe((string) $cashier->id)
        ->and($order->transaction()->sole()->created_by)->toBe((string) $cashier->id)
        ->and($order->orderItem()->sole()->created_by)->toBe((string) $cashier->id)
        ->and($order->chain_serial)->toBe(1)
        ->and($order->chain_hash)->not->toBeEmpty()
        ->and($writes)->not->toBeEmpty()
        ->and(min($writes))->toBeGreaterThan($outside);

    Queue::assertPushedOn('push', PushErpJob::class, fn (PushErpJob $job) => $job->orderId === (string) $order->id);
});

it('puts the sale on the cashier\'s open shift, and on none without one', function () {
    $cashier = User::factory()->create();
    $other = User::factory()->create();
    Shift::query()->create(['number' => 1, 'user_id' => (string) $other->id, 'opened_at' => now()->subHour(), 'opening_float' => 0]);
    $shift = Shift::query()->create(['number' => 2, 'user_id' => (string) $cashier->id, 'opened_at' => now()->subMinutes(5), 'opening_float' => 0]);

    $onShift = deskWithTea()->complete(tillCash(), 50.0, (string) $cashier->id);
    $noCashier = deskWithTea()->complete(tillCash(), 50.0);

    $shift->update(['closed_at' => now()]);
    $closed = deskWithTea()->complete(tillCash(), 50.0, (string) $cashier->id);

    expect((string) $onShift->shift_id)->toBe((string) $shift->id)
        ->and($noCashier->shift_id)->toBeNull()
        ->and($noCashier->created_by)->toBeNull()
        ->and($closed->shift_id)->toBeNull();
});

it('keeps nothing and keeps the cart when a part of the save fails', function () {
    $desk = deskWithTea();
    $lines = $desk->lines();

    // The save fails after the order, its lines and its payment are written: all of it is rolled back.
    $failing = new class extends CompleteSale
    {
        public function handle(array $sale): Order
        {
            return DB::transaction(function () use ($sale): Order {
                parent::handle($sale);

                throw new RuntimeException('disk full');
            });
        }
    };
    app()->instance(CompleteSale::class, $failing);

    expect(fn () => $desk->complete(tillCash(), 50.0, 'cashier-1'))->toThrow(RuntimeException::class, 'disk full')
        ->and(Order::query()->count())->toBe(0)
        ->and(OrderItem::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0)
        ->and($desk->lines())->toBe($lines);

    app()->forgetInstance(CompleteSale::class);

    expect($desk->complete(tillCash(), 50.0, 'cashier-1')->chain_serial)->toBe(1);
});

it('rolls back the order when its payment can not be saved', function () {
    $desk = deskWithTea();
    $sale = [
        'desktop_pos_id' => '42',
        'contact_id' => (string) DB::table('contacts')->value('id'),
        'cashier_id' => null,
        'totals' => $desk->totals([], null),
        'lines' => [['product_id' => $desk->lines()[0]['product_id'], 'name' => 'Tea', 'quantity' => 1, 'unit_id' => null, 'amount' => 50.0, 'vat' => null, 'vat_amount' => 0, 'subtotal' => 50.0]],
        // No method and no net amount: the payment can not be written.
        'payments' => [['amount' => 50.0]],
    ];

    expect(fn () => app(CompleteSale::class)->handle($sale))->toThrow(ErrorException::class)
        ->and(Order::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('seals each sale after the last one of the till', function () {
    $first = deskWithTea()->complete(tillCash(), 50.0);
    $second = deskWithTea()->complete(tillCash(), 50.0);

    expect($second->chain_prev_hash)->toBe($first->chain_hash)
        ->and($second->chain_hash)->toBe(SaleChain::hash($first->chain_hash, 2, $second->code, $second->net_amount, (string) $second->getRawOriginal('chain_signed_at')));
});
