<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;

/**
 * A sync problem seen on this terminal (see {@see SyncErrorLog}).
 */
class SyncError extends Model
{
    use HasUlids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
    ];
}
