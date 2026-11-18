<?php

namespace Rishadblack\SyllooPosCore\Models;

use Carbon\CarbonInterface;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooPosCore\Casts\TolerantEncrypted;
use Rishadblack\SyllooPosCore\Database\Factories\UserFactory;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;

/**
 * A cashier of this terminal: the shared base's member of a device ({@see DeviceMember}).
 */
class User extends Authenticatable implements DeviceMember
{
    use HasCachedImages, HasFactory, Notifiable;

    /**
     * The core's factory (the model is not in the app's namespace).
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = [
        'api_token',
    ];

    protected function casts(): array
    {
        return [
            'api_token' => TolerantEncrypted::class,
            'token_expires_at' => 'datetime',
            'offline_login_until' => 'datetime',
            'max_discount_percent' => 'integer',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * Cashiers whose ERP sign-in token has not expired yet (by this terminal's clock).
     */
    public function scopeWithValidToken(Builder $query): Builder
    {
        return $query->whereNotNull('users.api_token')
            ->where('users.token_expires_at', '>', now());
    }

    /**
     * The cashier whose token background jobs use when nobody is signed in: the most recently
     * issued token that has not expired (every token lives as long, so it expires last).
     */
    public static function latestWithValidToken(): ?self
    {
        return self::query()->withValidToken()->orderByDesc('users.token_expires_at')->first();
    }

    public function memberKey(): string
    {
        return (string) $this->getKey();
    }

    public function memberName(): string
    {
        return (string) $this->name;
    }

    public function photoUrl(): ?string
    {
        return is_string($this->image_url) && $this->image_url !== '' ? $this->image_url : null;
    }

    public function offlineSignInUntil(): ?CarbonInterface
    {
        return $this->offline_login_until;
    }

    public function hasValidToken(): bool
    {
        return filled($this->api_token) && $this->token_expires_at?->isFuture() === true;
    }

    /**
     * People who signed in on this terminal before and still have a sign-in here: the name tiles of the sign-in page.
     */
    public function scopeSignedInHere(Builder $query): Builder
    {
        return $query->whereNotNull('users.api_token');
    }

    /**
     * Allow PIN sign-in offline for the given number of days from now (at most 30), after the sign-in was confirmed
     * online.
     */
    public function allowOfflineSignInFor(int $days): void
    {
        $this->forceFill(['offline_login_until' => now()->addDays(max(0, min($days, 30)))])->save();
    }

    /**
     * Keep the ERP sign-in token issued to this cashier on this terminal.
     */
    public function storeToken(string $token, ?Carbon $expiresAt): void
    {
        $this->forceFill([
            'api_token' => $token,
            'token_expires_at' => $expiresAt ?? now()->addDay(),
        ])->save();
    }

    public function forgetToken(): void
    {
        $this->forceFill(['api_token' => null, 'token_expires_at' => null])->save();
    }

    /**
     * End this person's sign-in on this terminal (5 wrong PINs, or the office ended it): token and offline window go,
     * so they sign in with the browser again.
     */
    public function forgetSignIn(): void
    {
        $this->forceFill(['api_token' => null, 'token_expires_at' => null, 'offline_login_until' => null])->save();
    }

    /**
     * Forget every cashier's token and offline sign-in (the terminal was released: the ERP deleted them all).
     */
    public static function forgetAllTokens(): void
    {
        self::query()->update(['api_token' => null, 'token_expires_at' => null, 'offline_login_until' => null]);
    }
}
