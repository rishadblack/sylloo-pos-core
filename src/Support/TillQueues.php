<?php

namespace Rishadblack\SyllooPosCore\Support;

/**
 * The queue names of the till's jobs and workers, from `sylloo-pos-core.queues`. The PC till runs a worker per queue
 * (`push`, `pull`, `images`, `default`); a till with one worker (the Android till: NativePHP Mobile runs only the
 * default queue) maps every name to `default`.
 */
class TillQueues
{
    public const PUSH = 'push';

    public const PULL = 'pull';

    public const IMAGES = 'images';

    public const DEFAULT = 'default';

    /**
     * The queue a kind of work goes on: `push`, `pull`, `images` or `default`; an unset name is the kind itself.
     */
    public static function name(string $kind): string
    {
        $name = config('sylloo-pos-core.queues.'.$kind);

        return is_string($name) && $name !== '' ? $name : $kind;
    }

    /**
     * The queue workers a fresh start stops and an activation starts again (the default and the images worker),
     * each once.
     *
     * @return list<string>
     */
    public static function workers(): array
    {
        return array_values(array_unique([self::name(self::DEFAULT), self::name(self::IMAGES)]));
    }
}
