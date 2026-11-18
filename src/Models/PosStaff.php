<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Rishadblack\SyllooPosCore\Casts\TolerantEncrypted;

/**
 * Someone who may work on this terminal, as the ERP sent them (StaffDirectory): their POS permissions beyond selling
 * (price_override, void, refund, drawer, settings), their discount limit and the hash of their POS PIN.
 *
 * The PIN hash (bcrypt, from the ERP) is kept encrypted with the per-install key (audit P2, like the cashier tokens):
 * a copy of the database is no use for guessing PINs on another PC. A hash that can not be decrypted here reads as
 * null, so that person has no PIN on this terminal until the next staff list.
 */
class PosStaff extends Model
{
    use HasUlids;

    protected $table = 'pos_staff';

    protected $guarded = [];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'pin_hash' => TolerantEncrypted::class,
            'permissions' => 'array',
            'max_discount_percent' => 'integer',
        ];
    }

    /**
     * Whether this person has a PIN this terminal can check.
     */
    public function hasPin(): bool
    {
        return filled($this->pin_hash);
    }

    /**
     * Whether the PIN is theirs. A hash that could not be decrypted never matches.
     */
    public function pinMatches(string $pin): bool
    {
        return $this->hasPin() && Hash::check($pin, (string) $this->pin_hash);
    }

    public function may(string $permission): bool
    {
        return in_array($permission, (array) $this->permissions, true);
    }
}
