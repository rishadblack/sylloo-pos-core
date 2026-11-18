<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooAppBase\Device\ActivationKey as BaseActivationKey;

/**
 * The terminal's activation key, kept encrypted in the NativePHP settings (audit SEC-2), read the till's way (static
 * calls). The rule is the shared base's ({@see BaseActivationKey}) on the till's store (the base's DeviceStore; the PC till: SettingsDeviceStore):
 * with the per-install app key (SEC-1, held by Windows for this user) a copy of the settings file is no use on another
 * PC. A key saved before it was encrypted is encrypted on first read; a key that can not be decrypted is never sent to
 * the ERP as if it were a plain key: the terminal counts as not activated and opens the activation page (audit A11).
 */
final class ActivationKey
{
    public const SETTING = BaseActivationKey::SETTING;

    public static function get(): ?string
    {
        return self::key()->get();
    }

    public static function put(?string $key): void
    {
        self::key()->put($key);
    }

    /**
     * Whether a stored key exists but can not be decrypted (a copied settings file, a lost install key).
     */
    public static function isUnreadable(): bool
    {
        return self::key()->isUnreadable();
    }

    /**
     * Whether a value is a Laravel encrypted payload (base64 JSON with iv, value and mac) rather than a plain key.
     */
    public static function isEncryptedPayload(string $value): bool
    {
        return BaseActivationKey::isEncryptedPayload($value);
    }

    protected static function key(): BaseActivationKey
    {
        return DeviceState::instance()->activationKey();
    }
}
