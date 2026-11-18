<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rishadblack\SyllooPosCore\Services\StaffDirectory;
use Rishadblack\SyllooPosCore\Support\TillQueues;

/**
 * Take the staff list again (StaffDirectory): after a sign-in, on Sync now, and when the heartbeat shows another
 * version. Never fails a job: the terminal keeps the list it has.
 */
class PullStaff implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(TillQueues::name(TillQueues::PULL));
    }

    public function handle(StaffDirectory $staff): void
    {
        $staff->refresh();
    }
}
