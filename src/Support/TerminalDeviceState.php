<?php

namespace Rishadblack\SyllooPosCore\Support;

use Rishadblack\SyllooAppBase\Device\ActivationKey as BaseActivationKey;
use Rishadblack\SyllooAppBase\Device\DeviceState as BaseDeviceState;
use Rishadblack\SyllooPosCore\Services\SyncStatusService;

/**
 * The shared base's device state with the till's own parts: a terminal also needs its ERP terminal id
 * (`desktop_pos_id`) to count as activated, keeps whether its outlet is chosen (`outlet_ready`: the Terminal almost
 * ready state, registered in the base's StateRegistry by AppServiceProvider) and forgets the ERP health it showed with
 * the activation. The till reads it through {@see DeviceState}.
 */
class TerminalDeviceState extends BaseDeviceState
{
    /** The till's state screen after the shared ones: no POS outlet chosen yet. */
    public const ALMOST_READY = 'almost_ready';

    /**
     * The shared keys of the activation plus the till's own.
     *
     * @var list<string>
     */
    public const SETTINGS = [...BaseDeviceState::SETTINGS, 'desktop_pos_id', 'outlet_ready'];

    public function forgetActivation(): void
    {
        parent::forgetActivation();

        rescue(fn () => cache()->forget(SyncStatusService::SERVER_HEALTH_CACHE_KEY), report: false);
    }

    protected function requiredSettings(): array
    {
        return [BaseActivationKey::SETTING, 'desktop_pos_id'];
    }
}
