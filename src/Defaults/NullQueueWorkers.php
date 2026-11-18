<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;

/**
 * Queue workers when a till binds none: its platform runs the queue by itself.
 */
class NullQueueWorkers implements QueueWorkers
{
    public function up(string $queue): void {}

    public function down(string $queue): void {}
}
