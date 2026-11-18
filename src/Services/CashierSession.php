<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Events\SessionExpired;
use Rishadblack\SyllooPosCore\Models\SyncError;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\DeviceState;
use Throwable;

/**
 * A cashier's ERP sign-in session. The ERP answers 401 (`session_expired`) when the token is
 * missing, expired or was issued on another terminal; the cashier's token is then forgotten, the
 * sales stay pending, the problem is recorded once and the cashier is signed out of this terminal
 * with a message to sign in again.
 */
class CashierSession
{
    public const EXPIRED_CODE = 'session_expired';

    public const EXPIRED_TITLE = 'Your session ended';

    public const EXPIRED_MESSAGE = 'Your sign-in on this terminal ended. Sign in with your browser again.';

    /**
     * True when the ERP refused the cashier's token on a user endpoint (user, logout, sync, get-update, get-batch).
     */
    public static function isExpiredReply(Response $response): bool
    {
        // A 401 `device_released` is about the terminal, not the cashier: the till cleans itself (DeviceRelease).
        return $response->status() === 401 && ! DeviceState::isReleasedReply($response);
    }

    /**
     * End the cashier's session on this terminal after the ERP refused the token.
     */
    public static function expire(?User $user): void
    {
        if ($user) {
            $user->forgetSignIn();

            cache()->forever(self::expiredCacheKey($user->id), true);
        }

        $alreadyRecorded = SyncError::query()
            ->where('sync_errors.code', self::EXPIRED_CODE)
            ->whereNull('sync_errors.reported_at')
            ->exists();

        if (! $alreadyRecorded) {
            SyncErrorLog::record('auth', 'The sign-in session ended; the cashier must sign in again.', self::EXPIRED_CODE);
        }

        if (! $user) {
            return;
        }

        try {
            SessionExpired::dispatch((string) $user->id);
        } catch (Throwable $exception) {
            Log::warning('Cashier session: could not tell the windows the session ended: '.$exception->getMessage());
        }
    }

    /**
     * Sign the cashier out of this terminal (switch cashier). Their device sign-in stays, so their name tile and PIN
     * work next time; 5 wrong PINs or the office end it (PinSignIn).
     */
    public static function logout(?User $user): void
    {
        cache()->forget(self::expiredCacheKey((string) $user?->getKey()));
    }

    /**
     * True (once) when the ERP ended this cashier's session since the last check.
     */
    public static function pullExpired(string $userId): bool
    {
        return (bool) cache()->pull(self::expiredCacheKey($userId));
    }

    public static function expiredCacheKey(string $userId): string
    {
        return "pos.session_expired.{$userId}";
    }
}
