<?php

namespace Rishadblack\SyllooPosCore\Screens;

use Illuminate\Http\Client\HttpClientException;
use Rishadblack\SyllooAppBase\Contracts\DeviceMember;
use Rishadblack\SyllooAppBase\Device\DeviceState as BaseDeviceState;
use Rishadblack\SyllooAppBase\Device\StateDefinition;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooAppBase\Screens\Question;
use Rishadblack\SyllooAppBase\Screens\StateScreen;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Rishadblack\SyllooPosCore\Contracts\TillScreenFace;
use Rishadblack\SyllooPosCore\Facades\QueueWorker;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\PullStaff;
use Rishadblack\SyllooPosCore\Jobs\PullStart;
use Rishadblack\SyllooPosCore\Jobs\PullTerminalProfile;
use Rishadblack\SyllooPosCore\Jobs\SendHeartbeat;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\DeviceRelease;
use Rishadblack\SyllooPosCore\Services\ErpApiService;
use Rishadblack\SyllooPosCore\Services\ErpLicenceCheck;
use Rishadblack\SyllooPosCore\Services\ErpOrderedReset;
use Rishadblack\SyllooPosCore\Services\LocalDataReset;
use Rishadblack\SyllooPosCore\Services\PinSignIn;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;
use Rishadblack\SyllooPosCore\Support\DeviceState;
use Rishadblack\SyllooPosCore\Support\TerminalDeviceState;

/**
 * A till's parts of the shared screens (rishadblack/sylloo-app-base, nativephp-plan §4.4): Activate, Sign in, PIN
 * (with Create PIN on the same page) and the state screens are the base's, and a till adds what only a till does
 * through the base's hooks. The decisions are here, once for every till; what only a face does (the cashier's
 * session, a notice on the next screen, its own pieces) is the till's {@see TillScreenFace}.
 *
 * - Activate: the queue counts in the body; "Reset terminal for the new code?" before a code of another activation (the
 *   owner rule of 2026-10-26), the wipe only once the office accepted; an answer without the terminal is refused; the
 *   terminal setup after keeping it; "Terminal activated" on Continue; `released` after "Use another code"; every
 *   refusal in the sync error log.
 * - Sign in and PIN: a tap asks the office for the PIN hash (`GET me`); a kept browser sign-in pulls the staff list; a
 *   released device starts its release, a blocked one shows its state screen; the till signs the cashier in itself.
 * - State screens: Releasing with "Try again" every 15 seconds and its counted sentence, "Can't reach Sylloo" with
 *   "Try again", Update needed with "Update now" only, the plan-ended sentences (date, sales safe here, offline), and
 *   the heartbeat on every check.
 */
class TillScreens
{
    /** Seconds between checks while the terminal waits for its outlet or sends what waits before its clean-up. */
    public const POLL_SECONDS = 15;

    public function __construct(protected TillScreenFace $face) {}

    public static function register(Hooks $hooks, StateRegistry $states, TillScreenFace $face): void
    {
        $screens = new self($face);

        // The till's own state screen after the shared ones: no POS outlet chosen yet (Terminal almost ready).
        $states->register(
            new StateDefinition(TerminalDeviceState::ALMOST_READY, 'device.almost_ready_title', 'device.almost_ready_text', 'device.almost_ready_todo', pollSeconds: 15),
            fn (BaseDeviceState $device) => $device->get('outlet_ready') === false,
        );

        $screens->activate($hooks);
        $screens->signIn($hooks);
        $screens->states($hooks, $states);

        $face->addPieces($hooks);
    }

    protected function activate(Hooks $hooks): void
    {
        // The office's sync gate refuses a till that still holds sales of an earlier activation.
        $hooks->payload('activate', fn (array $context) => ErpOrderedReset::syncCounts());

        $hooks->ask('activate', 'reset', fn (array $context) => self::requiresLocalReset((string) ($context['code'] ?? '')) ? $this->resetQuestion() : null);

        $hooks->check('activate.answer', fn (array $answer) => self::hasTerminal($answer) ? null : __('device.unexpected'));

        $hooks->step('activate.before_keep', function (array $answer, array $context): void {
            // The cashier said yes to the reset question (it is asked whenever this is true): start fresh first.
            if (self::requiresLocalReset((string) ($context['code'] ?? ''))) {
                app(LocalDataReset::class)->handle();
            }

            // A new activation starts unlocked; the answer's `device.locked` (kept next) may lock it again.
            Settings::set('is_locked', 2);
            Settings::set('desktop_pos_id', (string) $answer['terminal']['desktop_pos_id']);
        });

        $hooks->step('activate.after_keep', function (array $answer): void {
            DeviceState::rememberOutletReady($answer['terminal']['outlet_ready'] ?? null);
            Order::rememberTerminalSerial($answer['terminal']['terminal_serial'] ?? null);

            QueueWorker::up('default');
            QueueWorker::up('images');

            PullTerminalProfile::dispatch();
            SendHeartbeat::dispatchDebounced('reconnect');

            // Every activation orders an `activation` reset in the ERP: this activation was it (ErpOrderedReset).
            app(ErpOrderedReset::class)->noteReply($answer['terminal']);
        });

        $hooks->step('activate.continue', fn () => $this->face->noticeOnNextPage(__('device.activated_title'), __('device.activated_text')));

        $hooks->step('use_another_code', function (): void {
            try {
                (new ErpApiService)->released();
            } catch (HttpClientException) {
                // Offline: the office removes a device nobody signed in on by itself after a day.
            }
        });

        $hooks->refused(function (array $refusal): void {
            if ($refusal['call'] !== 'activate') {
                return;
            }

            $code = is_string($refusal['code'] ?? null) && in_array($refusal['code'], SyncErrorLog::SERVER_CODES, true) ? $refusal['code'] : null;
            SyncErrorLog::record('activation', 'Activation refused: '.$refusal['status'].' - '.($refusal['message'] ?? 'No message'), $code);
        });
    }

    protected function signIn(Hooks $hooks): void
    {
        // A released device cleans itself; a device that may not sign anybody in shows its state screen.
        $hooks->goOnRefusal(function (array $refusal): ?string {
            $code = $refusal['code'] ?? null;

            if ($code === 'device_released' || DeviceState::isReleasing()) {
                app(DeviceRelease::class)->start();

                return 'state';
            }

            if ($refusal['call'] === 'sign_in' && in_array($code, ['plan_ended', 'business_suspended'], true)) {
                DeviceState::remember(['blocked' => $code]);

                return 'state';
            }

            return null;
        });

        // Online, the office's PIN hash and photo are fetched first, so a PIN changed elsewhere works (GET me).
        $hooks->step('sign_in.pick', fn (DeviceMember $member) => app(PinSignIn::class)->refresh($member));

        // The staff list brings the PIN hashes and permissions of this terminal's people.
        $hooks->step('sign_in.kept', function (): void {
            QueueWorker::up('default');
            PullStaff::dispatch();
        });

        $hooks->finish('signed_in', function (DeviceMember $member, bool $offline, ?SignedInPerson $person, array $context): string {
            assert($member instanceof User);

            $this->face->signIn($member);

            QueueWorker::up('default');

            // The cashier's "last synced" time is set by the pull once every page has been saved.
            PullStart::dispatch(userId: (string) $member->getKey());
            PullTerminalProfile::dispatch();

            $welcome = __('device.welcome', ['name' => $member->name]);

            match (true) {
                $person !== null => $this->face->noticeOnNextPage(__('device.signed_in'), $welcome.' '.__('device.use_pin_next')),
                ($context['step'] ?? null) === 'create' => $this->face->noticeOnNextPage(__('device.pin_saved'), $welcome),
                default => $this->face->noticeOnNextPage(__('device.signed_in'), $welcome),
            };

            return 'home';
        });
    }

    protected function states(Hooks $hooks, StateRegistry $states): void
    {
        $t = fn (string $key) => 'sylloo-app-base::device.'.$key;

        // The till's buttons and pace: Releasing tries again every 15 seconds, "Can't reach Sylloo" says Try again,
        // Update needed offers only the update.
        $states->register(new StateDefinition(BaseDeviceState::RELEASING, $t('releasing_title'), $t('releasing_text'), null, 'bi bi-box-arrow-right', 'danger', ['try_again'], self::POLL_SECONDS));
        $states->register(new StateDefinition(BaseDeviceState::UPDATE_NEEDED, $t('update_title'), $t('update_text'), $t('update_todo'), 'bi bi-cloud-arrow-down', 'warning', ['update_now']));
        $states->register(new StateDefinition(BaseDeviceState::UNREACHABLE, $t('unreachable_title'), $t('unreachable_text'), $t('unreachable_todo'), 'bi bi-wifi-off', 'secondary', ['try_again']));

        // "Check again" and the poll also ask the heartbeat: the office's lock and the outlet come with it.
        $hooks->step('state.check', function (): void {
            $check = app(ErpLicenceCheck::class);
            $heartbeat = $check->ask();

            if ($heartbeat !== null) {
                $check->rememberFromHeartbeat($heartbeat);
            }
        });

        $hooks->sentences(BaseDeviceState::PLAN_ENDED, function (array $context): array {
            $endsOn = DeviceState::plan()['ends_on'] ?? null;
            $date = is_string($endsOn) && $endsOn !== '' ? StateScreen::formatDate($endsOn) : '';
            $business = DeviceState::businessName() ?? __('device.this_business');
            $safe = rescue(fn () => DeviceState::salesWaiting(), 0, false);
            $pastValidUntil = DeviceState::get('blocked') !== 'plan_ended';

            return [
                'text' => $date !== '' ? __('device.plan_ended_text', ['business' => $business, 'date' => $date]) : __('device.plan_ended_text_undated', ['business' => $business]),
                'todo' => trim(($safe > 0 ? __('device.plan_ended_safe', ['count' => $safe]).' ' : '').($pastValidUntil ? __('device.plan_ended_offline') : __('device.plan_ended_todo'))),
            ];
        });

        $hooks->sentences(BaseDeviceState::RELEASING, function (): array {
            $refused = DeviceRelease::refusedSales();
            $sending = DeviceRelease::waitingSales();

            return ['todo' => match (true) {
                $refused > 0 => __('device.releasing_refused', ['count' => $refused]),
                $sending > 0 => __('device.releasing_sending', ['count' => $sending]),
                default => __('device.releasing_cleaning'),
            }];
        });
    }

    /**
     * "Reset terminal for the new code?": the data of another activation is removed once the office accepted the
     * code; "No" keeps everything and sends nothing.
     */
    protected function resetQuestion(): Question
    {
        $unsynced = Order::withTrashed()->whereNull('last_sync_at')->count();

        return new Question(
            __('device.reset_question_title'),
            trim(__('device.reset_question_text').' '.($unsynced > 0 ? __('device.reset_unsynced', ['count' => $unsynced]) : '')),
            __('device.reset_confirm'),
            __('device.reset_cancel'),
            danger: true,
            onCancel: fn () => ['text' => __('device.reset_cancelled_title').'. '.__('device.reset_cancelled_text'), 'variant' => 'info'],
        );
    }

    /**
     * Whether the cash of an open shift was never counted: the Releasing screen says so while the terminal is released.
     */
    public static function hasOpenShift(): bool
    {
        return Shift::query()->open()->exists();
    }

    /**
     * Whether activating this code must start the terminal fresh: it holds business data, or remembers another code.
     * The ERP returns a new random key on every activation, so the typed code is compared, never the key.
     */
    public static function requiresLocalReset(string $code): bool
    {
        $currentCode = DeviceState::get('activation_code');

        if (filled($currentCode) && (string) $currentCode !== $code) {
            return true;
        }

        return LocalDataReset::hasLocalData();
    }

    /**
     * Whether an activation answer names this terminal (audit A11): the base checks the key and the mode.
     *
     * @param  array<string, mixed>  $answer
     */
    public static function hasTerminal(array $answer): bool
    {
        $terminalId = is_array($answer['terminal'] ?? null) ? ($answer['terminal']['desktop_pos_id'] ?? null) : null;

        return (is_string($terminalId) || is_int($terminalId)) && (string) $terminalId !== '';
    }
}
