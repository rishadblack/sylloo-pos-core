<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rishadblack\SyllooPosCore\Services\PinSignIn;

/**
 * When the till opens online, ask the office (`GET me`) for the PIN hash and photo of every member signed in here, so
 * a PIN changed or cleared on another device works here too (PinSignIn::refreshAll()).
 */
class RefreshMemberPins implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** The office being away is not a failure: the next start asks again. */
    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(PinSignIn $pins): void
    {
        $pins->refreshAll();
    }
}
