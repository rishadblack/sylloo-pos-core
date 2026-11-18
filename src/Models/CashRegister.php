<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A cash register of the terminal's outlet in the ERP (pulled): a shift opens a session of this terminal's own
 * register, or of the outlet's only one (RegisterSync).
 */
class CashRegister extends Model
{
    use HasUlids;

    protected $guarded = [];

    /**
     * The register a shift on this terminal opens: its own, else the outlet's only active one. Null lets the ERP choose.
     */
    public static function forTerminal(?string $terminalId): ?self
    {
        $active = static::query()->where('status', 1);

        return ($terminalId ? (clone $active)->where('desktop_pos_id', $terminalId)->first() : null)
            ?? ((clone $active)->count() === 1 ? $active->first() : null);
    }
}
