<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Rishadblack\SyllooAppBase\Erp\ApiService as BaseApiService;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\ActivationKey;
use Rishadblack\SyllooPosCore\Support\DeviceState;

/**
 * Calls to the ERP from this terminal. The rules are the shared base's ({@see BaseApiService}: the device key in
 * `X-Device-Key`, the app version in `X-Sylloo-App`, the shared offline breaker, tries and backoff, https only in a
 * production build, a 401 `device_released` marks the terminal as releasing). The till adds its POS API
 * (`<erp.base_url>/pos/api/`, the default here; `onDeviceApi()` for `device/api/`) and its cashiers: requests carry the
 * given cashier's sign-in token, by default the signed-in cashier's; an expired token is not sent.
 */
class ApiService extends BaseApiService
{
    /**
     * The cashier whose sign-in token the requests carry, or null when they carry none.
     */
    protected ?User $tokenUser = null;

    public function __construct(?User $user = null)
    {
        $user ??= Auth::user() instanceof User ? Auth::user() : null;
        $user = $user?->hasValidToken() ? $user : null;

        parent::__construct(DeviceState::instance(), $user?->api_token);

        $this->baseUrl = config('erp.base_url').'/pos/api/';
        $this->tokenUser = $user;
    }

    public function tokenUser(): ?User
    {
        return $this->tokenUser;
    }

    /**
     * Keep the device key the ERP gave at activation (encrypted, see ActivationKey); null forgets it.
     */
    public function setActivationKey(?string $key = null): void
    {
        $this->activationKey = $key;
        ActivationKey::put($key);
    }
}
