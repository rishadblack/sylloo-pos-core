<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Rishadblack\SyllooPosCore\Contracts\CustomerDisplayScreen;

/**
 * A customer display when a till binds none: nothing is shown.
 */
class NullCustomerDisplayScreen implements CustomerDisplayScreen
{
    public function show(array $snapshot): void {}

    public function open(): void {}

    public function advertisementUrl(string $file): string
    {
        return $file;
    }
}
