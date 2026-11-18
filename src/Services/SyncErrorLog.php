<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Jobs\SendHeartbeat;
use Rishadblack\SyllooPosCore\Models\SyncError;
use Throwable;

/**
 * Local log of sync problems (failed pushes and pulls, orders the ERP rejected, licence and
 * terminal refusals). The heartbeat ({@see SendHeartbeat}) reports the unreported rows
 * to the ERP. Only the error text is kept, never order contents, amounts or customer details.
 */
class SyncErrorLog
{
    public const MAX_MESSAGE_LENGTH = 500;

    public const MAX_CODE_LENGTH = 60;

    /**
     * Rows older than this are pruned.
     */
    public const KEEP_DAYS = 30;

    /**
     * Only the newest rows are kept.
     */
    public const KEEP_ROWS = 200;

    /**
     * @var array<int, string>
     */
    public const TYPES = ['push', 'pull', 'auth', 'activation', 'other'];

    /**
     * Machine codes the ERP puts in the `code` of an error reply.
     *
     * @var array<int, string>
     */
    public const SERVER_CODES = [
        'outlet_not_set',
        'licence_expired',
        'terminal_inactive',
        'terminal_released',
        'not_allowed_on_terminal',
    ];

    /**
     * Record a sync problem. Logging never breaks the caller: a failure to write is only logged.
     *
     * @param  'push'|'pull'|'auth'|'activation'|'other'  $type
     */
    public static function record(string $type, string $message, ?string $code = null, ?string $orderId = null): ?SyncError
    {
        try {
            $syncError = SyncError::create([
                'occurred_at' => now(),
                'type' => in_array($type, self::TYPES, true) ? $type : 'other',
                'code' => $code !== null && $code !== '' ? mb_substr($code, 0, self::MAX_CODE_LENGTH) : null,
                'order_id' => $orderId !== null && $orderId !== '' ? mb_substr($orderId, 0, 26) : null,
                'message' => self::truncate($message),
            ]);

            self::prune();

            return $syncError;
        } catch (Throwable $exception) {
            Log::warning('Sync error log: could not record a sync error: '.$exception->getMessage());

            return null;
        }
    }

    /**
     * Record an error reply of the ERP, with its machine code when it carries one.
     *
     * @param  'push'|'pull'|'auth'|'activation'|'other'  $type
     */
    public static function recordResponse(string $type, Response $response, string $prefix = ''): ?SyncError
    {
        return self::record($type, $prefix.self::responseMessage($response), self::responseCode($response));
    }

    /**
     * The machine code of an ERP error reply (e.g. `licence_expired`), when it carries a known one.
     */
    public static function responseCode(Response $response): ?string
    {
        $code = rescue(fn () => $response->json('code'), null, false);

        return is_string($code) && in_array($code, self::SERVER_CODES, true) ? $code : null;
    }

    /**
     * The error text of an ERP reply: its `message` when it is JSON, otherwise the plain body.
     */
    public static function responseMessage(Response $response): string
    {
        $message = rescue(fn () => $response->json('message'), null, false);

        if (! is_string($message) || trim($message) === '') {
            $message = trim(strip_tags($response->body()));
        }

        return "{$response->status()} - ".($message !== '' ? $message : 'No message');
    }

    /**
     * The error text of a rejected order as the ERP sent it (a string, or a list / object of messages).
     */
    public static function rejectionMessage(mixed $response): string
    {
        if (is_string($response) && $response !== '') {
            return $response;
        }

        if (is_array($response)) {
            $message = $response['message'] ?? $response['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }

            $messages = array_filter(array_map(
                fn (mixed $value) => is_scalar($value) ? (string) $value : null,
                array_values($response),
            ));

            if ($messages !== []) {
                return implode('; ', $messages);
            }
        }

        return 'Rejected by ERP';
    }

    /**
     * The oldest errors not yet reported to the ERP.
     *
     * @return Collection<int, SyncError>
     */
    public static function unreported(int $limit = 20): Collection
    {
        return SyncError::query()
            ->whereNull('sync_errors.reported_at')
            ->orderBy('sync_errors.occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<int, string>  $ids
     */
    public static function markReported(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        SyncError::whereKey($ids)->update(['reported_at' => now()]);
    }

    /**
     * Keep the table small: drop rows older than {@see self::KEEP_DAYS} days and all but the newest {@see self::KEEP_ROWS}.
     */
    public static function prune(): void
    {
        SyncError::where('sync_errors.occurred_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        $newestIds = SyncError::query()
            ->orderByDesc('sync_errors.occurred_at')
            ->orderByDesc('sync_errors.id')
            ->limit(self::KEEP_ROWS)
            ->pluck('id');

        if ($newestIds->count() >= self::KEEP_ROWS) {
            SyncError::whereKeyNot($newestIds->all())->delete();
        }
    }

    public static function truncate(string $message): string
    {
        $message = trim($message);

        return mb_substr($message !== '' ? $message : 'Unknown error', 0, self::MAX_MESSAGE_LENGTH);
    }
}
