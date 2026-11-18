<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * The till's own key/value store of the activation and the sync state (the PC till: NativePHP Desktop's settings,
 * kept by Electron). Services and jobs reach it through the `Settings` facade of this package, never a platform's.
 */
interface TillSettings
{
    /**
     * The value kept under the key, or the default.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Keep a value under the key.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Forget the value under the key.
     */
    public function forget(string $key): void;

    /**
     * Forget every value (a fresh start).
     */
    public function clear(): void;
}
