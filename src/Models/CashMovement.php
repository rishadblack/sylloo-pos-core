<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash put into (`in`) or taken out of (`out`) the drawer during a shift, with the reason and who approved it.
 */
class CashMovement extends Model
{
    use HasUlids;

    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
