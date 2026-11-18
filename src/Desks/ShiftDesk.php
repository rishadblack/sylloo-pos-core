<?php

namespace Rishadblack\SyllooPosCore\Desks;

use Illuminate\Support\Collection;
use Rishadblack\SyllooPosCore\Models\CashMovement;
use Rishadblack\SyllooPosCore\Models\DailyCategory;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\CashDrawer;
use Rishadblack\SyllooPosCore\Services\ShiftRegister;
use Rishadblack\SyllooPosCore\Services\StaffDirectory;

/**
 * The cashier's shift as plain values (android-pos-plan.md §3.3, the Shift desk): open with a float, cash in and out
 * with a Daily book category, the X figures, and the close with the counted cash, the difference and the cash handover
 * to the main office ({@see ShiftRegister}, ../sylloo-erp-docs/erp/cash-handover.md §4). The rules of the PC till's
 * `WithTerminalShift`, with the Livewire validation turned into sentences: a face sends what the cashier typed (an
 * amount as text, Bangla digits too) and the cashier's user id, and shows the answer or the {@see ShiftRefused}
 * sentence. It names no screen and no platform.
 *
 * Taking cash out needs the `drawer` right; a manager's PIN for a cashier without it is the Approval desk's (phase 4),
 * so this desk refuses with a sentence until then.
 */
class ShiftDesk
{
    /** The largest amount a shift field takes (WithTerminalShift's `max:100000000`). */
    public const MAX_AMOUNT = 100000000;

    /** The right a cashier needs to take cash out alone (WithTerminalShift::moveCash()). */
    public const CASH_OUT_RIGHT = 'drawer';

    public function __construct(protected ShiftRegister $register, protected StaffDirectory $staff) {}

    /**
     * The cashier's open shift on this till, or null.
     */
    public function current(?string $cashierId): ?Shift
    {
        $cashier = $this->cashier($cashierId);

        return $cashier ? $this->register->current($cashier) : null;
    }

    /**
     * A shift another cashier left open on this till (one drawer, one shift): it is closed before this cashier opens
     * theirs.
     */
    public function otherOpenShift(?string $cashierId): ?Shift
    {
        $shift = $this->register->openOnTerminal();

        return $shift && (string) $shift->user_id !== (string) $cashierId ? $shift->loadMissing('user') : null;
    }

    /**
     * Why this cashier can not sell now, or null: every sale belongs to the cashier's open shift (Terminal::salesStore()).
     */
    public function saleProblem(?string $cashierId): ?string
    {
        return $this->current($cashierId) ? null : __('shift.open_first');
    }

    /**
     * Why the cashier can not open a shift now (another cashier's shift is still open), or null.
     */
    public function openProblem(?string $cashierId): ?string
    {
        $other = $this->otherOpenShift($cashierId);

        return $other ? __('shift.other_open', ['number' => $other->number, 'name' => $other->user instanceof User ? $other->user->memberName() : '—']) : null;
    }

    /**
     * What the last closed shift left in the drawer, shown as a hint (never filled in: the new cashier counts).
     */
    public function lastClosingFloat(): ?float
    {
        $shift = Shift::query()->whereNotNull('closed_at')->latest('closed_at')->first();

        return $shift?->closingFloat();
    }

    /**
     * What the office said about the last closed shift ({@see ShiftRegister::previousShiftNotice()}).
     */
    public function previousShiftNotice(): ?string
    {
        return $this->register->previousShiftNotice();
    }

    /**
     * The shift's X figures ({@see ShiftRegister::summary()}).
     *
     * @return array<string, mixed>
     */
    public function summary(Shift $shift): array
    {
        return $this->register->summary($shift);
    }

    /**
     * Open the cashier's shift with the cash counted in the drawer.
     *
     * @throws ShiftRefused
     */
    public function open(?string $cashierId, string|float|int|null $float): Shift
    {
        $cashier = $this->cashier($cashierId) ?? throw new ShiftRefused(__('shift.sign_in_first'));
        $amount = self::amount($float);

        if ($amount === null) {
            throw new ShiftRefused(__('shift.float_required'));
        }

        if ($amount < 0) {
            throw new ShiftRefused(__('shift.float_negative'));
        }

        if ($amount > self::MAX_AMOUNT) {
            throw new ShiftRefused(__('shift.amount_too_large'));
        }

        if ($problem = $this->openProblem($cashierId)) {
            throw new ShiftRefused($problem);
        }

        return $this->register->open($cashier, $amount);
    }

    /**
     * The Daily book categories for cash in or out (what the cash is for, so the office books it to the right account).
     *
     * @return Collection<int, DailyCategory>
     */
    public function categories(string $type): Collection
    {
        return DailyCategory::query()->usable($type === CashMovement::OUT ? DailyCategory::OUT : DailyCategory::IN)->get();
    }

    /**
     * Put cash into the drawer or take it out, with a reason (and a category when the office sent some). Taking it
     * out needs the `drawer` right. The drawer opens when the till has one.
     *
     * @throws ShiftRefused
     */
    public function moveCash(?string $cashierId, string $type, string|float|int|null $amount, ?string $reason, ?string $categoryId = null): CashMovement
    {
        $cashier = $this->cashier($cashierId) ?? throw new ShiftRefused(__('shift.sign_in_first'));
        $shift = $this->register->current($cashier) ?? throw new ShiftRefused(__('shift.open_first'));
        $value = self::amount($amount);
        $reason = trim((string) $reason);
        $categories = $this->categories($type);

        if (! in_array($type, [CashMovement::IN, CashMovement::OUT], true)) {
            throw new ShiftRefused(__('shift.cash_type_unknown'));
        }

        if ($value === null) {
            throw new ShiftRefused(__('shift.amount_required'));
        }

        if ($value <= 0) {
            throw new ShiftRefused(__('shift.amount_more_than_zero'));
        }

        if ($value > self::MAX_AMOUNT) {
            throw new ShiftRefused(__('shift.amount_too_large'));
        }

        if ($categories->isNotEmpty() && ! $categories->contains(fn (DailyCategory $category) => (string) $category->getKey() === (string) $categoryId)) {
            throw new ShiftRefused(__('shift.category_required'));
        }

        if ($reason === '') {
            throw new ShiftRefused(__('shift.reason_required'));
        }

        if ($type === CashMovement::OUT && ! $this->staff->cashierMay(self::CASH_OUT_RIGHT, null, $cashier)) {
            throw new ShiftRefused(__('shift.cash_out_needs_right'));
        }

        $movement = $this->register->move($shift, $type, round($value, 2), $reason, null, $categories->isEmpty() ? null : (string) $categoryId);

        if (CashDrawer::isOn()) {
            app(CashDrawer::class)->open();
        }

        return $movement;
    }

    /**
     * The close as the cashier types it: what the drawer should hold, the counted cash, short or over, the handover and
     * the cash that stays (the closing float), and why the handover can not be taken. Plain values, so the screen
     * shows them live.
     *
     * @return array{expected: float, counted: ?float, difference: ?float, handover: float, closing_float: ?float, problem: ?string, result: ?string}
     */
    public function closePreview(Shift $shift, string|float|int|null $counted, string|float|int|null $handover): array
    {
        $expected = (float) $this->register->summary($shift)['expected_cash'];
        $countedCash = self::amount($counted);
        $handoverAmount = self::amount($handover) ?? 0.0;
        $difference = $countedCash === null ? null : round($countedCash - $expected, 2);

        return [
            'expected' => round($expected, 2),
            'counted' => $countedCash,
            'difference' => $difference,
            'handover' => round($handoverAmount, 2),
            'closing_float' => $countedCash === null ? null : $this->register->closingFloat($countedCash, $handoverAmount),
            'problem' => $countedCash === null ? null : $this->register->handoverProblem($countedCash, $handoverAmount),
            'result' => $difference === null ? null : self::differenceSentence($difference),
        ];
    }

    /**
     * Close the cashier's shift with the counted cash, of which `$handover` goes to the main office; its figures are
     * frozen and it goes to the office with the register close (RegisterSync).
     *
     * @throws ShiftRefused
     */
    public function close(?string $cashierId, string|float|int|null $counted, string|float|int|null $handover = null, ?string $note = null): Shift
    {
        $cashier = $this->cashier($cashierId) ?? throw new ShiftRefused(__('shift.sign_in_first'));
        $shift = $this->register->current($cashier) ?? throw new ShiftRefused(__('shift.no_open_shift'));
        $countedCash = self::amount($counted);
        $handoverAmount = blank($handover) ? 0.0 : self::amount($handover);

        if ($countedCash === null) {
            throw new ShiftRefused(__('shift.counted_required'));
        }

        if ($countedCash < 0) {
            throw new ShiftRefused(__('shift.counted_negative'));
        }

        if ($countedCash > self::MAX_AMOUNT || ($handoverAmount ?? 0) > self::MAX_AMOUNT) {
            throw new ShiftRefused(__('shift.amount_too_large'));
        }

        if ($handoverAmount === null) {
            throw new ShiftRefused(__('shift.handover_numeric'));
        }

        // The same check every till makes before it closes.
        if ($problem = $this->register->handoverProblem($countedCash, $handoverAmount)) {
            throw new ShiftRefused($problem);
        }

        return $this->register->close($shift, $countedCash, $note, $handoverAmount);
    }

    /**
     * What the cashier is told once the shift is closed: its number, short / over / right, and the handover that goes
     * with the register close.
     */
    public function closedSentence(Shift $shift): string
    {
        $sentence = __('shift.closed', ['number' => $shift->number]).' '.self::differenceSentence((float) $shift->difference);

        if ($shift->hasHandover()) {
            $sentence .= ' '.__('shift.handover_waiting', ['amount' => numberFormat((float) $shift->handover_amount, true)]);
        }

        return $sentence;
    }

    /**
     * "The cash is right." / "Short by 50.00." / "Over by 20.00."
     */
    public static function differenceSentence(float $difference): string
    {
        return match (true) {
            abs($difference) < 0.005 => __('shift.cash_right'),
            $difference < 0 => __('shift.short_by', ['amount' => numberFormat(abs($difference), true)]),
            default => __('shift.over_by', ['amount' => numberFormat($difference, true)]),
        };
    }

    /**
     * An amount as the cashier typed it ("1,250", "১২৫০", "12.5"), or null when it is empty or not a number.
     */
    public static function amount(string|float|int|null $typed): ?float
    {
        if (is_int($typed) || is_float($typed)) {
            return (float) $typed;
        }

        $text = str_replace([',', ' '], '', trim(numberBnToEn((string) $typed)));

        return $text !== '' && is_numeric($text) ? (float) $text : null;
    }

    protected function cashier(?string $cashierId): ?User
    {
        return blank($cashierId) ? null : User::query()->find($cashierId);
    }
}
