<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ticket to a kitchen printer (erp: docs/restaurant.md): new lines, a cancel or a reprint, with a snapshot of its
 * lines, so it prints the same later. Waiting until printed, sent once handed to the printer, failed when it could not be.
 */
class KitchenTicket extends Model
{
    use HasUlids;

    public const KIND_NEW = 'new';

    public const KIND_CANCEL = 'cancel';

    public const KIND_REPRINT = 'reprint';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'on_receipt_printer' => 'boolean',
            'sent_at' => 'datetime',
            'tried_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * Tickets still to print: waiting, or failed and waiting for the next try.
     */
    public function scopeUnprinted(Builder $query): void
    {
        $query->whereIn('kitchen_tickets.status', [self::STATUS_WAITING, self::STATUS_FAILED]);
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('kitchen_tickets.status', self::STATUS_FAILED);
    }
}
