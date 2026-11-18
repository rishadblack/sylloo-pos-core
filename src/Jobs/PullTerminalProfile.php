<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rishadblack\SyllooPosCore\Services\TerminalProfile;

/**
 * Take the ERP's business and outlet details again (TerminalProfile): after a sign-in, on Sync now, and when the
 * heartbeat shows a newer copy. Never fails a job: the terminal keeps the copy it has.
 */
class PullTerminalProfile implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(PullErpJob::QUEUE);
    }

    public function handle(TerminalProfile $profile): void
    {
        $profile->refresh();
    }
}
