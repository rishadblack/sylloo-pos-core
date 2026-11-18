<?php

use Illuminate\Support\Facades\Queue;
use Rishadblack\SyllooPosCore\Desks\ShiftDesk;
use Rishadblack\SyllooPosCore\Desks\ShiftRefused;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Models\CashMovement;
use Rishadblack\SyllooPosCore\Models\DailyCategory;
use Rishadblack\SyllooPosCore\Models\PosStaff;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;

/*
 * The Shift desk (plain values, every till): open with a float, cash in and out, the live close figures with the cash
 * handover to the main office, and the close itself (ShiftRegister), with the cashier named, not taken from a session.
 */

beforeEach(function () {
    Queue::fake();
    $this->cashier = User::factory()->create(['name' => 'Rahim']);
    $this->desk = app(ShiftDesk::class);
});

function cashierWith(User $user, array $permissions): void
{
    PosStaff::query()->create(['id' => $user->id, 'name' => $user->name, 'permissions' => $permissions]);
}

it('opens the cashier\'s shift with the counted float, Bangla digits and grouping too', function () {
    $shift = $this->desk->open((string) $this->cashier->id, '১,২৫০');

    expect($shift->opening_float)->toBe(1250.0)
        ->and((string) $shift->user_id)->toBe((string) $this->cashier->id)
        ->and($this->desk->current((string) $this->cashier->id)?->id)->toBe($shift->id)
        ->and($this->desk->saleProblem((string) $this->cashier->id))->toBeNull();

    Queue::assertPushed(RegisterSync::class);
});

it('refuses an empty, negative or too large float, and no cashier', function (mixed $float, string $sentence) {
    expect(fn () => $this->desk->open((string) $this->cashier->id, $float))->toThrow(ShiftRefused::class, __($sentence));
})->with([
    'empty' => ['', 'shift.float_required'],
    'not a number' => ['abc', 'shift.float_required'],
    'negative' => ['-5', 'shift.float_negative'],
    'too large' => ['200000000', 'shift.amount_too_large'],
]);

it('needs a signed-in cashier', function () {
    expect(fn () => $this->desk->open(null, '100'))->toThrow(ShiftRefused::class, __('shift.sign_in_first'));
});

it('says a sale needs an open shift', function () {
    expect($this->desk->saleProblem((string) $this->cashier->id))->toBe(__('shift.open_first'))
        ->and($this->desk->saleProblem(null))->toBe(__('shift.open_first'));
});

it('does not open a second shift while another cashier\'s is open', function () {
    $karim = User::factory()->create(['name' => 'Karim']);
    $this->desk->open((string) $karim->id, '0');

    $sentence = __('shift.other_open', ['number' => 1, 'name' => 'Karim']);

    expect($this->desk->openProblem((string) $this->cashier->id))->toBe($sentence)
        ->and(fn () => $this->desk->open((string) $this->cashier->id, '100'))->toThrow(ShiftRefused::class, $sentence);
});

it('shows what the last shift left in the drawer', function () {
    $shift = $this->desk->open((string) $this->cashier->id, '500');
    $this->desk->close((string) $this->cashier->id, '1500', '1000');

    expect($this->desk->lastClosingFloat())->toBe(500.0);
});

it('puts cash in with a reason and a category when the office sent some', function () {
    $this->desk->open((string) $this->cashier->id, '100');
    $category = DailyCategory::query()->create(['name' => 'Change from bank', 'flow_type' => DailyCategory::IN, 'status' => 1]);

    expect(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '200', 'Change'))
        ->toThrow(ShiftRefused::class, __('shift.category_required'));

    $movement = $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '200', 'Change', (string) $category->id);

    expect($movement->amount)->toEqual(200)
        ->and((string) $movement->daily_category_id)->toBe((string) $category->id)
        ->and((string) $movement->user_id)->toBe((string) $this->cashier->id);
});

it('refuses a cash move without an amount, a reason or an open shift', function () {
    expect(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '200', 'x'))->toThrow(ShiftRefused::class, __('shift.open_first'));

    $this->desk->open((string) $this->cashier->id, '100');

    expect(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '', 'x'))->toThrow(ShiftRefused::class, __('shift.amount_required'))
        ->and(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '0', 'x'))->toThrow(ShiftRefused::class, __('shift.amount_more_than_zero'))
        ->and(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::IN, '50', '  '))->toThrow(ShiftRefused::class, __('shift.reason_required'));
});

it('takes cash out only with the drawer right', function () {
    $this->desk->open((string) $this->cashier->id, '1000');

    expect(fn () => $this->desk->moveCash((string) $this->cashier->id, CashMovement::OUT, '300', 'Tea'))
        ->toThrow(ShiftRefused::class, __('shift.cash_out_needs_right'));

    cashierWith($this->cashier, ['drawer']);
    $this->desk->moveCash((string) $this->cashier->id, CashMovement::OUT, '300', 'Tea');

    $shift = $this->desk->current((string) $this->cashier->id);

    expect($this->desk->summary($shift)['expected_cash'])->toBe(700.0);
});

it('shows the close live: expected, short or over, the handover and the cash that stays', function () {
    $shift = $this->desk->open((string) $this->cashier->id, '1000');

    $preview = $this->desk->closePreview($shift, '950', '600');

    expect($preview)->toMatchArray([
        'expected' => 1000.0,
        'counted' => 950.0,
        'difference' => -50.0,
        'handover' => 600.0,
        'closing_float' => 350.0,
        'problem' => null,
        'result' => __('shift.short_by', ['amount' => numberFormat(50, true)]),
    ])
        ->and($this->desk->closePreview($shift, '950', '1000')['problem'])->toBe(__('shift.handover_more_than_counted'))
        ->and($this->desk->closePreview($shift, '', '')['counted'])->toBeNull()
        ->and($this->desk->closePreview($shift, '1000', '')['result'])->toBe(__('shift.cash_right'));
});

it('closes with the counted cash and the handover, which goes with the register close', function () {
    $this->desk->open((string) $this->cashier->id, '1000');

    $closed = $this->desk->close((string) $this->cashier->id, '1020', '700', 'busy day');

    expect($closed->isOpen())->toBeFalse()
        ->and($closed->counted_cash)->toBe(1020.0)
        ->and($closed->handover_amount)->toBe(700.0)
        ->and($closed->difference)->toBe(20.0)
        ->and($closed->closingFloat())->toBe(320.0)
        ->and($closed->close_note)->toBe('busy day')
        ->and($this->desk->closedSentence($closed))->toBe(
            __('shift.closed', ['number' => 1]).' '.__('shift.over_by', ['amount' => numberFormat(20, true)]).' '
            .__('shift.handover_waiting', ['amount' => numberFormat(700, true)])
        );
});

it('refuses a close without a count, or with a handover above it, and keeps the shift open', function () {
    $this->desk->open((string) $this->cashier->id, '1000');

    expect(fn () => $this->desk->close((string) $this->cashier->id, '', '0'))->toThrow(ShiftRefused::class, __('shift.counted_required'))
        ->and(fn () => $this->desk->close((string) $this->cashier->id, '500', '600'))->toThrow(ShiftRefused::class, __('shift.handover_more_than_counted'))
        ->and(fn () => $this->desk->close((string) $this->cashier->id, '500', 'x'))->toThrow(ShiftRefused::class, __('shift.handover_numeric'))
        ->and(Shift::query()->open()->count())->toBe(1);
});

it('has its sentences in English and Bangla', function () {
    foreach (['shift', 'till'] as $file) {
        $en = array_keys(require __DIR__.'/../../../resources/lang/en/'.$file.'.php');
        $bn = array_keys(require __DIR__.'/../../../resources/lang/bn/'.$file.'.php');

        expect(array_diff($en, $bn))->toBe([])->and(array_diff($bn, $en))->toBe([]);
    }
});
