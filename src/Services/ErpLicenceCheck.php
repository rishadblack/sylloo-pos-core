<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooAppBase\Device\DeviceStateCheck;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\SendHeartbeat;
use Rishadblack\SyllooPosCore\Support\DeviceState;
use Throwable;

/**
 * Asks the ERP how this terminal stands, instead of trusting what the till remembers or what arrives from the browser
 * (audit A6, A11): "Check again" on the state screens, and the release, lock and unlock commands of the ERP socket,
 * which a script in the window could also send.
 *
 * - `GET device/api/v1/state` ({@see self::askState()}, the shared base's DeviceStateCheck): plan, blocked (plan
 *   ended, business paused, released), minimum version. Kept by DeviceState.
 * - The heartbeat reply ({@see self::ask()}): the lock state an admin set (`data.locked`), whether the outlet is set,
 *   a reset the ERP ordered, and the same device state under `data.device`.
 */
class ErpLicenceCheck
{
    /**
     * Ask `GET state` and keep the answer. Returns the answer, ['blocked' => 'device_released'] when the key no
     * longer works, or null when the ERP could not be asked.
     *
     * @return array<string, mixed>|null
     */
    public function askState(): ?array
    {
        return (new DeviceStateCheck(DeviceState::instance(), fn () => new ErpApiService))->askState();
    }

    /**
     * The heartbeat's answer about this terminal, or null when it could not be asked.
     *
     * @return array{status: ?string, locked: ?bool, outlet_ready: ?bool, reset: ?array<string, mixed>, device: ?array<string, mixed>}|null
     */
    public function ask(): ?array
    {
        if (! SendHeartbeat::terminalActivated()) {
            return null;
        }

        try {
            $response = (new ErpApiService)->health((new SendHeartbeat('interval'))->payload());
        } catch (Throwable $exception) {
            Log::info('Terminal check: the ERP could not be reached: '.$exception->getMessage());

            return null;
        }

        return self::answerFrom($response);
    }

    /**
     * What a heartbeat reply says about the terminal, or null when it says nothing usable. A key that no longer works
     * reads as released.
     *
     * @return array{status: ?string, locked: ?bool, outlet_ready: ?bool, reset: ?array<string, mixed>, device: ?array<string, mixed>}|null
     */
    public static function answerFrom(Response $response): ?array
    {
        if (DeviceState::isReleasedReply($response)) {
            return ['status' => 'released', 'locked' => null, 'outlet_ready' => null, 'reset' => null, 'device' => ['blocked' => 'device_released']];
        }

        if (! $response->successful()) {
            return null;
        }

        $data = rescue(fn () => $response->json('data'), null, false);

        if (! is_array($data)) {
            return null;
        }

        $locked = $data['locked'] ?? null;

        return [
            'status' => is_string($data['terminal_status'] ?? null) ? $data['terminal_status'] : null,
            'locked' => is_bool($locked) ? $locked : null,
            'outlet_ready' => is_bool($data['outlet_ready'] ?? null) ? $data['outlet_ready'] : null,
            'reset' => is_array($data['reset'] ?? null) ? $data['reset'] : null,
            'device' => is_array($data['device'] ?? null) ? $data['device'] : null,
        ];
    }

    /**
     * Whether the ERP confirms the device was released.
     *
     * @param  array<string, mixed>|null  $answer  of {@see self::ask()} or {@see self::askState()}
     */
    public static function confirmsRelease(?array $answer): bool
    {
        return DeviceStateCheck::confirmsRelease($answer);
    }

    /**
     * Whether the reply orders a reset of the terminal (ErpOrderedReset).
     *
     * @param  array<string, mixed>|null  $answer
     */
    public static function ordersReset(?array $answer): bool
    {
        return is_array($answer['reset'] ?? null) && ($answer['reset']['required'] ?? false) === true;
    }

    /**
     * Whether the ERP confirms the terminal may be used again: not released and not saying it is locked.
     *
     * @param  array<string, mixed>|null  $answer
     */
    public static function confirmsUnlock(?array $answer): bool
    {
        return $answer !== null && ! self::confirmsRelease($answer) && ($answer['locked'] ?? null) !== true;
    }

    /**
     * Keep what a heartbeat reply says: the device state, whether the outlet is set and the lock state. Returns whether
     * a screen may change (so the windows look again).
     *
     * @param  array{status: ?string, locked: ?bool, outlet_ready: ?bool, reset: ?array<string, mixed>, device: ?array<string, mixed>}  $answer
     */
    public function rememberFromHeartbeat(array $answer): bool
    {
        $before = DeviceState::screen();

        if (is_array($answer['device'])) {
            DeviceState::remember($answer['device']);
        }

        DeviceState::rememberOutletReady($answer['outlet_ready']);

        if ($answer['locked'] !== null && ! self::confirmsRelease($answer)) {
            Settings::set('is_locked', $answer['locked'] ? 1 : 2);
        }

        return DeviceState::screen() !== $before;
    }
}
