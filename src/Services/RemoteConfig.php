<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooAppBase\Config\RemoteConfig as BaseRemoteConfig;

/**
 * The signed remote config: pos-config.json in the public releases repo (config('erp.remote_config.url')). The rules
 * are the shared base's ({@see BaseRemoteConfig}): with it every till can be moved to a new ERP or socket address,
 * told the latest and the oldest supported version, and shown a short notice, without a new release. A file is taken
 * only when its Ed25519 signature matches the public key built into the app (php artisan release:sign-config), every
 * address in it is https on an allowed domain and its `serial` is higher than that of the file the till holds. The
 * last good file is kept under the till's own path and applied on every request.
 */
class RemoteConfig extends BaseRemoteConfig
{
    public const PATH = 'remote-config/pos-config.json';
}
