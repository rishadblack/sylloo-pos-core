<?php

namespace Rishadblack\SyllooPosCore\Support;

use Illuminate\Http\Client\Response;
use Rishadblack\SyllooAppBase\Device\DeviceState as BaseDeviceState;
use Rishadblack\SyllooPosCore\Models\Order;

/**
 * What this terminal knows about its device and its business (../sylloo-erp-docs/erp/device-api.md), read the till's
 * way (static calls). The rules are the shared base's ({@see BaseDeviceState}, through the till's
 * {@see TerminalDeviceState}): kept in the till's device store (the PC till: the NativePHP settings, SettingsDeviceStore) from the activation answer,
 * `GET device/api/v1/state` and the heartbeat's `device` key, so the till also knows it offline. It decides which
 * screen a page shows instead of selling ({@see self::screen()}).
 *
 * The device key itself is kept encrypted by ActivationKey (setting `activation_key`), the mode in `license_mode`
 * (read by PracticeMode) and the terminal's ERP id in `desktop_pos_id` (its socket channel).
 */
final class DeviceState
{
    public const NOT_ACTIVATED = BaseDeviceState::NOT_ACTIVATED;

    public const RELEASING = BaseDeviceState::RELEASING;

    public const LOCKED = BaseDeviceState::LOCKED;

    public const BUSINESS_SUSPENDED = BaseDeviceState::BUSINESS_SUSPENDED;

    public const PLAN_ENDED = BaseDeviceState::PLAN_ENDED;

    public const UPDATE_NEEDED = BaseDeviceState::UPDATE_NEEDED;

    public const ALMOST_READY = TerminalDeviceState::ALMOST_READY;

    /** Days before the plan ends from which the plan bar shows (plan §5.5). */
    public const PLAN_BAR_DAYS = BaseDeviceState::PLAN_BAR_DAYS;

    /**
     * Settings of the activation: forgotten when the device is released or uses another code, kept by a reset the ERP
     * orders (ErpOrderedReset).
     *
     * @var list<string>
     */
    public const SETTINGS = TerminalDeviceState::SETTINGS;

    /** Setting that marks a released device that still sends what waits, then cleans itself (DeviceRelease). */
    public const RELEASING_SETTING = BaseDeviceState::RELEASING_SETTING;

    /** Setting with the business a device was released from, for the line on the Activate screen. */
    public const RELEASED_FROM_SETTING = BaseDeviceState::RELEASED_FROM_SETTING;

    /** The random id of this install, sent at every activation (no hardware fingerprint). */
    public const DEVICE_UID_SETTING = BaseDeviceState::DEVICE_UID_SETTING;

    /**
     * The shared base's device state of this terminal.
     */
    public static function instance(): TerminalDeviceState
    {
        return app(BaseDeviceState::class);
    }

    public static function isActivated(): bool
    {
        return self::instance()->isActivated();
    }

    /**
     * The screen this terminal shows instead of its pages, or null when it may work. In this order: releasing, not
     * activated, locked from the office, business paused, plan ended (Live only), update needed, outlet not chosen.
     *
     * @return self::*|null
     */
    public static function screen(): ?string
    {
        return self::instance()->screen();
    }

    /**
     * Keep what the ERP says about this device: the answer of `activate`, `GET state` or the heartbeat's `device`.
     *
     * @param  array<string, mixed>  $state
     */
    public static function remember(array $state): void
    {
        self::instance()->remember($state);
    }

    /**
     * Whether a POS outlet is chosen for this terminal (activation answer `terminal.outlet_ready`, heartbeat).
     */
    public static function rememberOutletReady(mixed $ready): void
    {
        if (is_bool($ready) && self::get('outlet_ready') !== $ready) {
            self::instance()->store()->set('outlet_ready', $ready);
        }
    }

    /**
     * The ERP released this device: it stops selling, sends what waits, then cleans itself (DeviceRelease).
     */
    public static function markReleasing(): void
    {
        self::instance()->markReleasing();
    }

    public static function isReleasing(): bool
    {
        return self::instance()->isReleasing();
    }

    /**
     * Whether an ERP answer says the device key no longer works (401 `device_released`).
     */
    public static function isReleasedReply(Response $response): bool
    {
        return BaseDeviceState::isReleasedReply($response);
    }

    public static function isPractice(): bool
    {
        return self::instance()->isPractice();
    }

    public static function businessName(): ?string
    {
        return self::instance()->businessName();
    }

    public static function deviceName(): ?string
    {
        return self::instance()->deviceName();
    }

    /**
     * The host the browser sign-in opens, for "We opened xyz.sylloo.app" (always the Live address, plan §5.3).
     */
    public static function signInHost(): ?string
    {
        return self::instance()->signInHost();
    }

    /**
     * The portal page where the owner renews the plan ("Renew plan", the plan bar), as the ERP sent it, or null.
     */
    public static function portalUrl(): ?string
    {
        return self::instance()->portalUrl();
    }

    /**
     * Keep the business logo address and a local copy of it (ImageCache: https, Sylloo hosts only).
     */
    public static function rememberLogo(mixed $url): void
    {
        self::instance()->rememberLogo($url);
    }

    /**
     * The local copy of the business logo to show, or null (the screens then show the first letter of the name).
     */
    public static function logoSource(): ?string
    {
        return self::instance()->logoSource();
    }

    /**
     * An address the till may open in the browser: https (http outside production).
     */
    public static function isSafeUrl(mixed $url): bool
    {
        return BaseDeviceState::isSafeUrl($url);
    }

    /**
     * Days a cashier may sign in by PIN offline after the till last confirmed their sign-in online.
     */
    public static function offlineLoginDays(): int
    {
        return self::instance()->offlineLoginDays();
    }

    /**
     * @return array{active: bool, ends_on: ?string, in_grace: bool, valid_until: ?string}|null
     */
    public static function plan(): ?array
    {
        return self::instance()->plan();
    }

    /**
     * Whether a Live terminal may not sell because the plan ended (Practice never ends, decision 17).
     */
    public static function planEnded(): bool
    {
        return self::instance()->planEnded();
    }

    /**
     * Days left of a Live plan when it ends within {@see self::PLAN_BAR_DAYS} or is in its days of grace, else null.
     */
    public static function planEndsInDays(): ?int
    {
        return self::instance()->planEndsInDays();
    }

    public static function needsUpdate(): bool
    {
        return self::instance()->needsUpdate();
    }

    /**
     * Sales on this terminal the office does not have yet (they are safe here and go up later).
     */
    public static function salesWaiting(): int
    {
        return Order::syncPending()->count() + Order::syncUploading()->count() + Order::syncFailed()->count();
    }

    /**
     * The random id of this install (made once, kept by a release).
     */
    public static function deviceUid(): string
    {
        return self::instance()->deviceUid();
    }

    /**
     * Forget the activation and everything the ERP said about it (not the business data; LocalDataReset does that).
     */
    public static function forgetActivation(): void
    {
        self::instance()->forgetActivation();
    }

    /**
     * A setting, or null when it can not be read (a page never breaks over it).
     */
    public static function get(string $key): mixed
    {
        return self::instance()->get($key);
    }
}
