<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Rishadblack\SyllooPosCore\Database\Seeders\SettingSeeder;
use Rishadblack\SyllooPosCore\Facades\QueueWorker;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Support\DeviceState;

/**
 * Starts the terminal fresh for a new license: every local record, the cache, cached ERP images, the shop's own files
 * (receipt logo and template, advertisements), the settings exports, the logs (they hold whole ERP records), the
 * database copies, sales exports and support files of LocalBackup in every folder used, the kept remote config and the
 * NativePHP settings.
 * The queue workers are stopped first, so a job of the old business can not write its data back after the wipe
 * (audit A9); the activation starts them again.
 */
class LocalDataReset
{
    /**
     * Queue workers stopped before the wipe (see config/nativephp.php).
     *
     * @var list<string>
     */
    public const QUEUE_WORKERS = ['default', 'images'];

    /**
     * Tables that only hold data of the business the terminal was activated for.
     *
     * @var list<string>
     */
    private const BUSINESS_TABLES = ['orders', 'products', 'contacts', 'payment_methods', 'categories', 'brands'];

    /**
     * Shop files under storage/app, relative to it: advertisements, the receipt template and the settings exports
     * written when there is no Downloads folder.
     *
     * @var list<string>
     */
    private const SHOP_DIRECTORIES = ['advertisements', 'invoices', 'exports'];

    /**
     * Till settings that belong to the computer, not to the business: kept when a released device cleans itself
     * (../sylloo-erp-docs/erp/device-api.md §3): the printer, paper and print settings, the look, the drawer and the
     * backup folder.
     *
     * @var list<string>
     */
    public const COMPUTER_SETTINGS = [
        'touch_mode', 'terminal_theme', 'terminal_text', 'cash_drawer', 'backup_folder', 'idle_lock_minutes',
        'scale_barcode_prefixes', 'scale_barcode_kind', 'scale_barcode_item_digits', 'scale_barcode_decimals',
    ];

    /**
     * NativePHP settings that belong to the computer: the install's random device id and the language.
     *
     * @var list<string>
     */
    public const COMPUTER_NATIVE_SETTINGS = [DeviceState::DEVICE_UID_SETTING, 'locale'];

    /**
     * True when the terminal still holds records of a business.
     */
    public static function hasLocalData(): bool
    {
        foreach (self::BUSINESS_TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove every tenant-owned local record, the cache and the local files.
     */
    public function handle(bool $keepComputerSettings = false): void
    {
        $kept = $keepComputerSettings ? $this->computerSettings() : null;

        foreach (self::QUEUE_WORKERS as $worker) {
            rescue(fn () => QueueWorker::down($worker), report: false);
        }

        Auth::logout();

        // Before the settings table is emptied: it names the backup folders.
        app(LocalBackup::class)->deleteAll();

        Schema::disableForeignKeyConstraints();

        try {
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                if ($table !== 'migrations' && ! str_starts_with($table, 'sqlite_')) {
                    DB::table($table)->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Cache::flush();
        (new SettingSeeder)->run();

        Storage::disk(ImageCache::DISK)->deleteDirectory(ImageCache::DIRECTORY);
        Storage::disk('public')->deleteDirectory(ReceiptPrintService::LOGO_FOLDER);

        // The shared base's waiting list of offline writes and their files (rishadblack/sylloo-app-base Rest).
        Storage::disk(config('sylloo-app-base.rest.waiting.disk', 'local'))
            ->deleteDirectory(trim((string) config('sylloo-app-base.rest.waiting.folder', 'sylloo-waiting'), '/'));

        foreach (self::SHOP_DIRECTORIES as $directory) {
            File::deleteDirectory(storage_path('app/'.$directory));
        }

        $this->deleteLogs();

        // The till's own folders (the PC till: its compiled code cache): the same for every business, but nothing local
        // outlives a fresh start. A file a running
        // PHP process still holds stays until the next reset or uninstall; OPcache compiles a missing one again.
        foreach ((array) config('sylloo-pos-core.reset.storage_folders', []) as $folder) {
            rescue(fn () => File::deleteDirectory(storage_path((string) $folder)), report: false);
        }

        Settings::clear();

        // The kept remote config goes too; it is downloaded again at once, so the activation that follows already
        // reaches a moved ERP (offline, the till uses the addresses it was built with until the next download).
        $remoteConfig = app(RemoteConfig::class);
        $remoteConfig->forget();
        rescue(fn () => $remoteConfig->refresh(), report: false);

        if ($kept !== null) {
            $this->restoreComputerSettings($kept);
        }
    }

    /**
     * The computer's own settings ({@see self::COMPUTER_SETTINGS}, every receipt and printer setting and
     * {@see self::COMPUTER_NATIVE_SETTINGS}) as they are now.
     *
     * @return array{till: array<string, ?string>, native: array<string, mixed>}
     */
    protected function computerSettings(): array
    {
        $parameters = [...self::COMPUTER_SETTINGS, ...array_keys(Setting::RECEIPT_DEFAULTS)];
        $native = [];

        foreach (self::COMPUTER_NATIVE_SETTINGS as $key) {
            $native[$key] = rescue(fn () => Settings::get($key), null, false);
        }

        return [
            'till' => Setting::query()->whereIn('parameter', $parameters)->pluck('value', 'parameter')->all(),
            'native' => $native,
        ];
    }

    /**
     * @param  array{till: array<string, ?string>, native: array<string, mixed>}  $kept
     */
    protected function restoreComputerSettings(array $kept): void
    {
        foreach ($kept['till'] as $parameter => $value) {
            Setting::query()->updateOrCreate(['parameter' => $parameter], ['value' => $value]);
        }

        foreach (array_filter($kept['native'], fn (mixed $value) => $value !== null) as $key => $value) {
            Settings::set($key, $value);
        }
    }

    /**
     * Remove the log files. One still open for writing (this request's own log) can not be deleted on Windows, so it
     * is emptied instead.
     */
    protected function deleteLogs(): void
    {
        foreach (File::glob(storage_path('logs/*.log')) ?: [] as $log) {
            if (! File::delete($log)) {
                rescue(fn () => File::put($log, ''), report: false);
            }
        }
    }
}
