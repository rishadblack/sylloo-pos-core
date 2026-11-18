<?php

namespace Rishadblack\SyllooPosCore\Facades;

use Illuminate\Support\Facades\Facade;
use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;

/**
 * The till's background workers ({@see QueueWorkers}), answered by each till.
 *
 * @method static void up(string $queue)
 * @method static void down(string $queue)
 */
class QueueWorker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return QueueWorkers::class;
    }
}
