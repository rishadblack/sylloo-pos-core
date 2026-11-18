<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\ShiftRegister;

/*
 * Cash handed over to the main office at the close of a shift (../sylloo-erp-docs/erp/cash-handover.md §4): the same
 * check on every till (ShiftRegister), and the handover sent with the register close (RegisterSync).
 */

beforeEach(function () {
    Queue::fake();
    config(['erp.base_url' => 'https://erp.test']);
    $this->cashier = User::factory()->create(['api_token' => 'cashier-token', 'token_expires_at' => now()->addDay()]);
});

/**
 * A shift closed on the till whose register session is open in the office.
 *
 * @param  array<string, mixed>  $attributes
 */
function handoverShift(array $attributes = []): Shift
{
    return Shift::query()->create([
        'number' => Shift::query()->count() + 1,
        'user_id' => test()->cashier->id,
        'opened_at' => now()->subHours(8),
        'closed_at' => now(),
        'opening_float' => 100,
        'counted_cash' => 1500,
        'handover_amount' => 1000,
        'register_status' => RegisterSync::OPEN,
        'cash_register_session_id' => (string) Str::ulid(),
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function closedSessionReply(bool $withHandover = true): array
{
    return ['data' => ['session' => [
        'id' => (string) Str::ulid(),
        'status' => 'closed',
        'expected' => 1500.0,
        'difference' => 0.0,
        'closing_float' => 500.0,
        ...($withHandover ? ['handover' => ['id' => (string) Str::ulid(), 'amount' => 1000.0, 'status' => 1, 'to_account_id' => (string) Str::ulid(), 'to_account' => 'Main Office Cash']] : []),
    ]]];
}

it('checks the handover against the counted cash and works out the closing float', function (float $counted, float $handover, ?string $problem, float $float) {
    $register = app(ShiftRegister::class);

    expect($register->handoverProblem($counted, $handover))->toBe($problem === null ? null : __($problem))
        ->and($register->closingFloat($counted, $handover))->toBe($float);
})->with([
    'none' => [1500, 0, null, 1500.0],
    'part of the cash' => [1500, 1000, null, 500.0],
    'all of the cash' => [1500, 1500, null, 0.0],
    'more than counted' => [1500, 1500.01, 'shift.handover_more_than_counted', 0.0],
    'negative' => [1500, -1, 'shift.handover_negative', 1500.0],
]);

it('keeps the handover on the closed shift', function () {
    $shift = app(ShiftRegister::class)->open($this->cashier, 100);

    app(ShiftRegister::class)->close($shift, 1500, null, 1000);

    expect($shift->fresh())
        ->handover_amount->toBe(1000.0)
        ->closingFloat()->toBe(500.0)
        ->hasHandover()->toBeTrue();
});

it('refuses a handover of more than the counted cash and leaves the shift open', function () {
    $shift = app(ShiftRegister::class)->open($this->cashier, 100);

    expect(fn () => app(ShiftRegister::class)->close($shift, 500, null, 600))
        ->toThrow(InvalidArgumentException::class, __('shift.handover_more_than_counted'));

    expect($shift->fresh())->isOpen()->toBeTrue()->handover_amount->toBe(0.0);
});

it('sends the handover with the register close and keeps the main-office account of the answer', function () {
    $shift = handoverShift();
    Http::fake(['*/v1/register/close' => Http::response(closedSessionReply())]);

    (new RegisterSync)->handle();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/register/close')
        && $request['handover_amount'] === 1000.0
        && $request['counted_cash'] === 1500.0
        && $request['cash_register_session_id'] === $shift->cash_register_session_id);

    expect($shift->fresh())
        ->register_status->toBe(RegisterSync::CLOSED)
        ->register_message->toBeNull()
        ->handover_account->toBe('Main Office Cash')
        ->and(app(ShiftRegister::class)->previousShiftNotice())->toContain('Main Office Cash')->toContain('Shift 1');
});

it('sends no handover field without a handover', function () {
    handoverShift(['handover_amount' => 0]);
    Http::fake(['*/v1/register/close' => Http::response(closedSessionReply(withHandover: false))]);

    (new RegisterSync)->handle();

    Http::assertSent(fn (Request $request) => ! array_key_exists('handover_amount', $request->data()));
    expect(app(ShiftRegister::class)->previousShiftNotice())->toBeNull();
});

it('tells the cashier to hand the cash over by hand when the office closed without taking it', function () {
    $shift = handoverShift();
    Http::fake(['*/v1/register/close' => Http::response(closedSessionReply(withHandover: false))]);

    (new RegisterSync)->handle();

    expect($shift->fresh())
        ->register_status->toBe(RegisterSync::CLOSED)
        ->register_message->toBe(__('shift.handover_not_taken', ['amount' => numberFormat(1000, true)]));
});

it('keeps the close and tries it again while the office has no account for the handover', function (string $code) {
    $shift = handoverShift();
    Http::fake(['*/v1/register/close' => Http::sequence()
        ->push(['code' => $code, 'message' => 'Set it in Ledger Settings first.'], 409)
        ->push(closedSessionReply())]);

    (new RegisterSync)->handle();

    expect($shift->fresh())
        ->register_status->toBe(RegisterSync::OPEN)
        ->register_message->toBe('Set it in Ledger Settings first.')
        ->and(RegisterSync::hasWork())->toBeTrue()
        ->and(app(ShiftRegister::class)->previousShiftNotice())->toBe('Shift 1: Set it in Ledger Settings first.');

    (new RegisterSync)->handle();

    expect($shift->fresh())->register_status->toBe(RegisterSync::CLOSED)->register_message->toBeNull();
    Http::assertSentCount(2);
})->with(['cash_in_transit_not_set', 'main_cash_not_set']);

it('refuses for good a handover the office finds too large', function () {
    $shift = handoverShift();
    Http::fake(['*/v1/register/close' => Http::response(['code' => 'handover_too_large', 'message' => ''], 422)]);

    (new RegisterSync)->handle();

    expect($shift->fresh())
        ->register_status->toBe(RegisterSync::REFUSED)
        ->register_message->toBe(__('shift.handover_too_large'));
});

it('takes a close answered as already closed, with its handover, after a lost reply', function () {
    $shift = handoverShift();
    Http::fake(['*/v1/register/close' => Http::response(['code' => 'register_closed', 'message' => 'Closed.', ...closedSessionReply()], 409)]);

    (new RegisterSync)->handle();

    expect($shift->fresh())
        ->register_status->toBe(RegisterSync::CLOSED)
        ->handover_account->toBe('Main Office Cash');
});
