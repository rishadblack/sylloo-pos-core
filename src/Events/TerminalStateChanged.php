<?php

namespace Rishadblack\SyllooPosCore\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The heartbeat changed how this terminal may be used: the ERP locked or unlocked it (audit A6), or ordered a reset
 * (audit A7). Sent to the app's windows (WithWindowTitle::terminalStateChanged()), which reload when it is safe, so the
 * activation page, the sign-in page or the reset follows without waiting for the cashier's next page.
 */
class TerminalStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

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
