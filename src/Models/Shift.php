<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A cashier's shift on this terminal (ShiftRegister): opened with a float, closed with a cash count, of which part may be
 * handed over to the main office (`handover_amount`). Its number is the Z number printed on the report.
 *
 * @property string $id
 * @property int $number
 * @property string $user_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property float $opening_float
 * @property float|null $expected_cash
 * @property float|null $counted_cash
 * @property float $handover_amount
 * @property string|null $handover_account
 * @property float|null $difference
 * @property array<string, mixed>|null $totals
 * @property string|null $close_note
 * @property string|null $cash_register_id
 * @property string|null $cash_register_session_id
 * @property string $register_status
 * @property string|null $register_message
 * @property float|null $erp_expected_cash
 * @property float|null $erp_difference
 */
class Shift extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'float',
            'expected_cash' => 'float',
            'counted_cash' => 'float',
            'handover_amount' => 'float',
            'difference' => 'float',
            'erp_expected_cash' => 'float',
            'erp_difference' => 'float',
            'totals' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Whether cash of this shift is handed over to the main office (ShiftRegister::close).
     */
    public function hasHandover(): bool
    {
        return (float) $this->handover_amount > 0;
    }

    /**
     * The cash left in the drawer once the shift is closed: the counted cash less the handover. Null while it is open.
     */
    public function closingFloat(): ?float
    {
        return $this->counted_cash === null ? null : round((float) $this->counted_cash - (float) $this->handover_amount, 2);
    }
}
