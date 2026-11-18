<?php

use Illuminate\Support\Facades\Queue;
use Rishadblack\SyllooPosCore\Desks\CustomerDesk;
use Rishadblack\SyllooPosCore\Desks\CustomerRefused;
use Rishadblack\SyllooPosCore\Desks\OrdersDesk;
use Rishadblack\SyllooPosCore\Desks\SaleDesk;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\ContactGroup;
use Rishadblack\SyllooPosCore\Models\Order;

/*
 * The Customer desk (find by mobile or name, recent, quick add, the dues book) and the Orders desk (today's orders with
 * the Not sent, Baki and Held filters), as plain values for every till.
 */

beforeEach(function () {
    Queue::fake();
    readyTill();
    $this->customers = app(CustomerDesk::class);
});

function deskCustomer(string $name, string $mobile, array $attributes = []): Contact
{
    return Contact::query()->create([
        'type' => 1, 'name' => $name, 'mobile' => $mobile, 'status' => 1,
        'contact_group_id' => ContactGroup::query()->value('id'), ...$attributes,
    ]);
}

it('finds a customer by part of the mobile number or by the name, never the walk-in', function () {
    deskCustomer('Salma Begum', '01711000321', ['balance' => 2300, 'overdue' => 800, 'credit_limit' => 5000]);
    deskCustomer('Jamal Uddin', '01815000440');

    expect(collect($this->customers->find('321'))->pluck('name')->all())->toBe(['Salma Begum'])
        ->and(collect($this->customers->find('০৪৪০'))->pluck('name')->all())->toBe(['Jamal Uddin'])
        ->and(collect($this->customers->find('begum salma'))->pluck('name')->all())->toBe(['Salma Begum'])
        ->and($this->customers->find('walk'))->toBe([])
        ->and($this->customers->find('  '))->toBe([])
        ->and($this->customers->find('321')[0])->toMatchArray(['owed' => 2300.0, 'overdue' => 800.0, 'limit' => 5000.0, 'over_limit' => false]);
});

it('adds a new customer from a name and a mobile number, in the default group', function () {
    $contact = $this->customers->add(' Nasrin Akter ', '017-1100 0999');

    expect($contact->name)->toBe('Nasrin Akter')
        ->and($contact->mobile)->toBe('01711000999')
        ->and((int) $contact->type)->toBe(1)
        ->and((string) $contact->contact_group_id)->toBe((string) ContactGroup::query()->value('id'))
        ->and($contact->desktop_pos_id)->toBe('42')
        ->and($contact->code)->toStartWith('CUS');
});

it('picks the known customer of a mobile number instead of making a second one', function () {
    $salma = deskCustomer('Salma Begum', '01711000321');

    expect((string) $this->customers->add('Someone else', '01711000321')->id)->toBe((string) $salma->id)
        ->and(Contact::query()->where('mobile', '01711000321')->count())->toBe(1)
        ->and(Contact::query()->find($salma->id)->name)->toBe('Salma Begum');
});

it('refuses a new customer without a mobile number or a name', function (?string $name, ?string $mobile, string $sentence) {
    expect(fn () => $this->customers->add($name, $mobile))->toThrow(CustomerRefused::class, __($sentence));
})->with([
    'no mobile' => ['Nasrin', '', 'till.customer_mobile_required'],
    'letters' => ['Nasrin', 'abc123', 'till.customer_mobile_invalid'],
    'too short' => ['Nasrin', '123', 'till.customer_mobile_invalid'],
    'no name' => ['', '01711000999', 'till.customer_name_required'],
]);

it('lists this till\'s recent customers, newest first', function () {
    $salma = deskCustomer('Salma Begum', '01711000321');
    $jamal = deskCustomer('Jamal Uddin', '01815000440');

    $sale = function (Contact $contact) {
        $desk = SaleDesk::forContact((string) $contact->id);
        $desk->add(tillProduct('Tea', 50.0)->id);
        $desk->complete(tillCash(), 50.0);
    };

    $sale($salma);
    $this->travel(1)->minutes();
    $sale($jamal);

    expect(collect($this->customers->recent())->pluck('name')->all())->toBe(['Jamal Uddin', 'Salma Begum']);
});

it('keeps the dues book: who owes, overdue first, with this till\'s own Baki sales', function () {
    deskCustomer('Salma Begum', '01711000321', ['balance' => 3550, 'overdue' => 800]);
    deskCustomer('Hotel Al-Amin', '01911000001', ['balance' => 12000, 'overdue' => 12000]);
    deskCustomer('Paid Up', '01911000002', ['balance' => 0]);
    $jamal = deskCustomer('Jamal Uddin', '01815000440');

    $desk = SaleDesk::forContact((string) $jamal->id);
    $desk->add(tillProduct('Oil', 2100.0)->id);
    $desk->completeOnBaki(tillCash(), 0, now()->addDays(30)->toDateString());

    $book = $this->customers->duesBook();

    expect(collect($book['rows'])->pluck('name')->all())->toBe(['Hotel Al-Amin', 'Salma Begum', 'Jamal Uddin'])
        ->and($book['total'])->toBe(17650.0)
        ->and($book['overdue'])->toBe(12800.0)
        ->and(collect($this->customers->duesBook('', 'overdue')['rows'])->pluck('name')->all())->toBe(['Hotel Al-Amin', 'Salma Begum'])
        ->and(collect($this->customers->duesBook('jamal')['rows'])->pluck('name')->all())->toBe(['Jamal Uddin']);
});

it('lists today\'s orders with the Not sent, Baki and Held filters', function () {
    $salma = deskCustomer('Salma Begum', '01711000321');

    $cashSale = SaleDesk::forWalkIn();
    $cashSale->add(tillProduct('Tea', 50.0)->id);
    $cash = $cashSale->complete(tillCash(), 50.0);

    $baki = SaleDesk::forContact((string) $salma->id);
    $baki->add(tillProduct('Rice', 1250.0)->id);
    $bakiOrder = $baki->completeOnBaki(tillCash(), 0, now()->addDays(30)->toDateString());

    $cash->forceFill(['last_sync_at' => now()])->save();
    Order::query()->create(['code' => 'HELD-1', 'desktop_pos_id' => '42', 'type' => 1, 'status' => Order::STATUS_DRAFT, 'order_date' => now(), 'contact_id' => $salma->id, 'net_amount' => 10]);
    Order::query()->whereKey($bakiOrder->id)->update(['order_date' => now()]);

    $orders = app(OrdersDesk::class);
    $all = $orders->today();

    expect(collect($all)->pluck('code')->all())->toContain($cash->code, $bakiOrder->code)
        ->and(collect($all)->pluck('code')->all())->not->toContain('HELD-1')
        ->and(collect($orders->today('not_sent'))->pluck('code')->all())->toBe([$bakiOrder->code])
        ->and(collect($orders->today('baki'))->pluck('code')->all())->toBe([$bakiOrder->code])
        ->and(collect($orders->today('held'))->pluck('code')->all())->toBe(['HELD-1'])
        ->and(collect($orders->today('all', 'salma'))->pluck('code')->all())->toBe([$bakiOrder->code])
        ->and($orders->counts())->toBe(['all' => 2, 'not_sent' => 1, 'baki' => 1, 'held' => 1])
        ->and(collect($all)->firstWhere('code', $cash->code))->toMatchArray(['state' => 'synced', 'state_text' => __('till.order_synced'), 'kind' => 'sale'])
        ->and(collect($all)->firstWhere('code', $bakiOrder->code))->toMatchArray(['state' => 'pending', 'due' => 1250.0, 'customer' => 'Salma Begum']);
});
