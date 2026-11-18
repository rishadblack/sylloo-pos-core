<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Models\AuditLog;
use Throwable;

/**
 * What cashiers did beyond selling (audit Phase 3): a line discount or price change, an order discount above their own
 * limit, a removed line or cleared order with its reason, a reprint, an approval refused, and who approved it. Kept
 * here and sent to the ERP with the heartbeat (erp: PosAuditLog).
 */
class AuditTrail
{
    /** Entries sent with one heartbeat. */
    public const PER_HEARTBEAT = 200;

    /**
     * @param  array<string, mixed>  $details
     */
    public static function record(string $action, array $details = [], ?string $reason = null, ?string $orderCode = null, ?string $approvedBy = null): void
    {
        try {
            AuditLog::query()->create([
                'action' => $action,
                'user_id' => Auth::id(),
                'approved_by' => $approvedBy,
                'order_code' => $orderCode,
                'reason' => $reason !== null ? mb_substr($reason, 0, 191) : null,
                'details' => $details === [] ? null : $details,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // A record that can not be written never stops the cashier.
            Log::warning("Audit trail: could not record {$action}: ".$exception->getMessage());
        }
    }

    /**
     * The entries not sent yet, as the heartbeat sends them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function unreported(): Collection
    {
        return AuditLog::query()->whereNull('reported_at')->orderBy('occurred_at')->limit(self::PER_HEARTBEAT)->get()
            ->map(fn (AuditLog $entry) => [
                'id' => (string) $entry->id,
                'action' => $entry->action,
                'user_id' => $entry->user_id,
                'approved_by' => $entry->approved_by,
                'order_code' => $entry->order_code,
                'reason' => $entry->reason,
                'details' => $entry->details,
                'at' => $entry->occurred_at?->toIso8601String(),
            ]);
    }

    /**
     * @param  list<string>  $ids
     */
    public static function markReported(array $ids): void
    {
        if ($ids !== []) {
            AuditLog::query()->whereKey($ids)->update(['reported_at' => now()]);
        }
    }
}
