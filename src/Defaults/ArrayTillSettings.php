<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Rishadblack\SyllooPosCore\Contracts\TillSettings;

/**
 * The settings store when a till binds none: kept in memory for one process (tests, a till under construction).
 */
class ArrayTillSettings implements TillSettings
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(protected array $values = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }
}
