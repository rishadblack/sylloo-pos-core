<?php

use Illuminate\Support\Facades\Queue;
use Rishadblack\SyllooPosCore\Desks\SaleDesk;
use Rishadblack\SyllooPosCore\Desks\SaleRefused;
use Rishadblack\SyllooPosCore\Models\AuditLog;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\ContactGroup;
use Rishadblack\SyllooPosCore\Models\PosStaff;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\SaleDueDate;

/*
 * Baki (a sale left owing) on the sale desk (owner decision 2026-11-18): a customer's sale, the due date by itself
 * (SaleDueDate: term, else 30 days) and changeable, part paid in cash, the credit limit, never for the walk-in.
 */

beforeEach(function () {
    Queue::fake();
    readyTill();
    $this->cashier = User::factory()->create();
});

function bakiCustomer(array $attributes = []): Contact
{
    return Contact::query()->create([
        'type' => 1, 'name' => 'Salma Begum', 'mobile' => '01711000321', 'status' => 1,
        'contact_group_id' => ContactGroup::query()->value('id'), ...$attributes,
    ]);
}

function bakiDesk(?Contact $customer = null, float $price = 1250.0): SaleDesk
{
    $desk = SaleDesk::forContact($customer?->id);
    $desk->add(tillProduct('Rice', $price)->id);

    return $desk;
}

it('prices a desk for the chosen customer, and for the walk-in when there is none', function () {
    $customer = bakiCustomer();

    expect(SaleDesk::forContact((string) $customer->id)->contactId())->toBe((string) $customer->id)
        ->and(SaleDesk::forContact((string) $customer->id)->isWalkIn())->toBeFalse()
        ->and(SaleDesk::forContact(null)->isWalkIn())->toBeTrue()
        ->and(SaleDesk::forContact('no-such-id')->contactId())->toBe(SaleDesk::forWalkIn()->contactId());
});

it('shows the Baki figures and the due date that fills itself', function () {
    $this->travelTo('2026-11-18 10:00');
    $customer = bakiCustomer(['balance' => 2300, 'credit_limit' => 5000]);

    expect(bakiDesk($customer)->bakiFigures(250.0))->toBe([
        'total' => 1250.0,
        'paid' => 250.0,
        'due' => 1000.0,
        'owes_now' => 2300.0,
        'will_owe' => 3300.0,
        'limit' => 5000.0,
        'over_limit' => false,
        'due_date' => '2026-12-18',
    ])->and(bakiDesk($customer)->bakiFigures(1250.0)['due_date'])->toBeNull();
});

it('saves a sale wholly on Baki with its due date, owed by the customer', function () {
    $customer = bakiCustomer();
    $date = now()->addDays(SaleDueDate::DEFAULT_DAYS)->toDateString();

    $order = bakiDesk($customer)->completeOnBaki(tillCash(), 0, $date, (string) $this->cashier->id);

    expect((float) $order->due_amount)->toBe(1250.0)
        ->and((float) $order->paid_amount)->toBe(0.0)
        ->and($order->due_date->toDateString())->toBe($date)
        ->and((int) $order->payment_status)->toBe(2)
        ->and((string) $order->contact_id)->toBe((string) $customer->id)
        ->and($order->transaction()->count())->toBe(0)
        ->and((string) $order->created_by)->toBe((string) $this->cashier->id);
});

it('saves a part paid in cash and the rest on Baki, with a date the cashier chose', function () {
    $customer = bakiCustomer();
    $date = now()->addDays(7)->toDateString();

    $order = bakiDesk($customer)->completeOnBaki(tillCash(), 500, $date, (string) $this->cashier->id);

    expect((float) $order->paid_amount)->toBe(500.0)
        ->and((float) $order->due_amount)->toBe(750.0)
        ->and($order->due_date->toDateString())->toBe($date)
        ->and((int) $order->payment_status)->toBe(3)
        ->and((float) $order->transaction()->sole()->amount)->toBe(500.0);
});

it('refuses Baki for the walk-in customer, with nothing owed, or without a good due date', function () {
    $date = now()->addDays(30)->toDateString();
    $customer = bakiCustomer();

    expect(fn () => bakiDesk()->completeOnBaki(tillCash(), 0, $date))->toThrow(SaleRefused::class, __('till.baki_needs_customer'))
        ->and(fn () => bakiDesk($customer)->completeOnBaki(tillCash(), 1250, $date))->toThrow(SaleRefused::class, __('till.baki_nothing_owed'))
        ->and(fn () => bakiDesk($customer)->completeOnBaki(tillCash(), 0, ''))->toThrow(SaleRefused::class, __('till.baki_due_date_required', ['amount' => numberFormat(1250, true)]))
        ->and(fn () => bakiDesk($customer)->completeOnBaki(tillCash(), 0, '2026-02-30'))->toThrow(SaleRefused::class)
        ->and(fn () => bakiDesk($customer)->completeOnBaki(tillCash(), 0, now()->subDay()->toDateString()))->toThrow(SaleRefused::class, __('till.baki_due_date_past'))
        ->and(fn () => bakiDesk($customer)->completeOnBaki(tillCash(), -1, $date))->toThrow(SaleRefused::class, __('till.amount_not_negative'));
});

it('still refuses a short cash sale on complete(): that is Baki', function () {
    expect(fn () => bakiDesk(bakiCustomer())->complete(tillCash(), 100))->toThrow(SaleRefused::class, __('till.not_enough', ['amount' => numberFormat(1150, true)]));
});

it('needs the credit limit right to go over the customer\'s limit, and records it', function () {
    $customer = bakiCustomer(['balance' => 4500, 'credit_limit' => 5000]);
    $date = now()->addDays(30)->toDateString();
    $desk = bakiDesk($customer);

    expect($desk->bakiFigures()['over_limit'])->toBeTrue()
        ->and(fn () => $desk->completeOnBaki(tillCash(), 0, $date, (string) $this->cashier->id))->toThrow(SaleRefused::class, __('till.baki_over_limit', [
            'name' => 'Salma Begum', 'owed' => numberFormat(4500, true), 'limit' => numberFormat(5000, true),
        ]));

    PosStaff::query()->create(['id' => $this->cashier->id, 'name' => 'Rahim', 'permissions' => ['credit_limit']]);
    $order = $desk->completeOnBaki(tillCash(), 0, $date, (string) $this->cashier->id);

    $audit = AuditLog::query()->sole();

    expect($audit->action)->toBe('credit_limit_over')
        ->and((string) $audit->user_id)->toBe((string) $this->cashier->id)
        ->and($audit->order_code)->toBe($order->code);
});
