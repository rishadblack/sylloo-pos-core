<?php

namespace Rishadblack\SyllooPosCore\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Like Laravel's `encrypted` cast, but a value that can not be decrypted reads as null instead of breaking the page:
 * with the per-install key (AppServiceProvider::useInstallKey()) a value encrypted under a key that is gone (another
 * Windows user, a restored database) only means signing in online again.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TolerantEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Crypt::encryptString((string) $value);
    }
}
