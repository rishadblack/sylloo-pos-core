<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a cashier did beyond selling, with who approved it (AuditTrail); sent to the ERP with the heartbeat.
 */
class AuditLog extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }
}
