<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooAppBase\Contracts\AppUpdater;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Update\AppUpdateService as BaseAppUpdateService;

/**
 * Tells the cashier about new app versions, both when the app starts and while it is open. The rules are the shared
 * base's ({@see BaseAppUpdateService}) on the till's updater ({@see AppUpdater}; the PC: Electron's) and notices
 * ({@see Notifier}; the PC: Windows notices); the till passes the updater's events on.
 *
 * Electron checks for an update on every launch; while the app runs, the scheduler checks hourly and the ERP can ask
 * for a check over the socket. Each version is announced once per launch: "available" when the download starts,
 * "ready" when it can be installed. The cashier can also check by hand (tray panel and app menu); that check always
 * answers, including "up to date" and "couldn't check". The notices stay in English, as the till always showed them
 * (config `sylloo-app-base.texts.locale`).
 */
class AppUpdateService extends BaseAppUpdateService
{
    public const NOTIFIED_CACHE_KEY = 'pos.update_notified';

    /**
     * Set while a check started by the app itself runs. Electron's launch check shows its own
     * "ready to install" notification, so ours is only needed for these checks.
     */
    public const APP_CHECK_CACHE_KEY = 'pos.update_check_by_app';

    /**
     * Set while a check the cashier started by hand runs, so its outcome is always reported.
     */
    public const MANUAL_CHECK_CACHE_KEY = 'pos.update_check_manual';

    public function __construct(?AppUpdater $updater = null, ?Notifier $notifier = null)
    {
        parent::__construct($updater ?? app(AppUpdater::class), $notifier ?? app(Notifier::class));
    }

    protected function productName(): string
    {
        return 'Sylloo POS';
    }

    /**
     * The tray panel shows that a new version installs on the next start.
     */
    protected function rememberUpdateReady(string $version): void
    {
        SyncStatusService::rememberUpdateReady($version);
    }

    /**
     * The new version may change the database when it starts: keep a copy from before.
     */
    protected function beforeInstall(): void
    {
        app(LocalBackup::class)->run('before-update');
    }
}
