<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rishadblack\SyllooPosCore\Services\RemoteConfig;

/**
 * Downloads the signed remote config ({@see RemoteConfig}) and takes it when it is newer. Queued when the app starts,
 * every hour and when the ERP asks (`refresh_config` on the terminal or announcement channel). A failed download is
 * not retried: the next run tries again.
 */
class RefreshRemoteConfig implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(RemoteConfig $remoteConfig): void
    {
        $remoteConfig->refresh();
    }
}
