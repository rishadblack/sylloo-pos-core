<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Carbon;
use Rishadblack\SyllooAppBase\Device\DeviceTestRunner as BaseDeviceTestRunner;
use Rishadblack\SyllooAppBase\Erp\DeviceApi;

/**
 * The ERP's connection test of this till (ERP Settings › Devices › Test, ../sylloo-erp-docs/erp/device-test-plan.md).
 * It arrives on the terminal's socket as the command `device_test`; the till checks itself and answers with
 * `POST device/api/v1/test-result`. The shared checks are the base's ({@see BaseDeviceTestRunner}: `connection`,
 * `socket`, `notification`); the till adds `heartbeat` (the health report is taken), `sales_queue` (no refused sales)
 * and one `sync.<Model>` per data type the till pulls (pulled at least once).
 */
class DeviceTestRunner extends BaseDeviceTestRunner
{
    public function __construct(private SyncStatusService $sync) {}

    protected function deviceApi(): DeviceApi
    {
        return new ErpApiService;
    }

    protected function askState(): ?array
    {
        return app(ErpLicenceCheck::class)->askState();
    }

    protected function notificationText(): string
    {
        return 'Notifications work on this till.';
    }

    protected function moreChecks(): array
    {
        $checks = [];

        $health = $this->attempt(fn () => app(ErpLicenceCheck::class)->ask());
        $checks[] = $this->check('heartbeat', $health !== null, 'The health report did not reach Sylloo.');

        $counts = $this->attempt(fn () => $this->sync->orderCounts()) ?? ['pending' => 0, 'uploading' => 0, 'failed' => 0];
        $waiting = (int) $counts['pending'] + (int) $counts['uploading'];
        $refused = (int) $counts['failed'];
        $checks[] = $this->check('sales_queue', $refused === 0,
            $refused > 0 ? "{$refused} sales were refused." : null,
            $waiting > 0 ? "{$waiting} sales waiting to send." : null);

        foreach ($this->attempt(fn () => $this->sync->dataTypes()) ?? [] as $type) {
            $last = $type['last_pulled_at'] ?? null;
            $checks[] = $this->check('sync.'.$type['name'], $last instanceof Carbon, 'Never pulled.',
                $last instanceof Carbon ? 'Pulled '.$last->diffForHumans().', '.$type['records'].' records.' : null);
        }

        return $checks;
    }
}
