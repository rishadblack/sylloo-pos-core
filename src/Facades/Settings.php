<?php

namespace Rishadblack\SyllooPosCore\Facades;

use Illuminate\Support\Facades\Facade;
use Rishadblack\SyllooPosCore\Contracts\TillSettings;

/**
 * The till's settings store ({@see TillSettings}), answered by each till.
 *
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static void forget(string $key)
 * @method static void clear()
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TillSettings::class;
    }
}
