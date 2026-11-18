<?php

namespace Rishadblack\SyllooPosCore\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something the tray panel shows has changed (orders, queue, sign-in, connection, update).
 *
 * Not a ShouldBroadcast event: NativePHP relays any event on the "nativephp" channel to
 * every window, where the tray panel picks it up with Native.on() and redraws itself.
 */
class SyncStatusChanged
{
    use Dispatchable;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('nativephp'),
        ];
    }
}
