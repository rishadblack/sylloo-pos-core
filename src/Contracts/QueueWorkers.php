<?php

namespace Rishadblack\SyllooPosCore\Contracts;

/**
 * Starting and stopping the till's background workers by queue name (`default`, `images`). The PC till: NativePHP
 * Desktop's queue workers. A till whose platform runs its queue by itself answers with nothing.
 */
interface QueueWorkers
{
    /**
     * Start the worker of the queue (nothing when it already runs).
     */
    public function up(string $queue): void;

    /**
     * Stop the worker of the queue.
     */
    public function down(string $queue): void;
}
