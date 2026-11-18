<?php

namespace Rishadblack\SyllooPosCore\Support;

/**
 * The till's running version, as the shared base reads it (config `sylloo-app-base.app.version`, else the version the
 * native shell was built with), but not shortened: the heartbeat, backups and the settings export send it whole.
 */
final class AppVersion
{
    public static function current(): mixed
    {
        return config('sylloo-app-base.app.version') ?? config('nativephp.version');
    }
}
