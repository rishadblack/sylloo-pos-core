<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Support\AppVersion;
use Throwable;

/**
 * Copies of the till's database, so sales that have not reached the ERP survive a dead disk or a reinstall:
 * one copy a day (and one before an app update) in the backup folder set on the Settings page, the last
 * {@see self::KEEP} kept. Also exports the unsent sales as JSON and CSV for support.
 */
class LocalBackup
{
    /**
     * Every file this class writes starts with it, so only those are ever removed.
     */
    public const FILE_PREFIX = 'sylloo-pos-';

    public const KEEP = 7;

    /**
     * The settings table parameter that lists every backup folder used before (JSON).
     */
    public const FOLDERS_SETTING = 'backup_folders_used';

    /**
     * The files this class writes, by pattern: the database copies, the unsynced sales exports and the support files
     * (SupportBundle). A fresh start removes only these, never another sylloo-pos-* file such as a settings export.
     *
     * @var list<string>
     */
    public const FILE_PATTERNS = [
        self::FILE_PREFIX.'[0-9]*.sqlite',
        self::FILE_PREFIX.'unsynced-sales-*.json',
        self::FILE_PREFIX.'unsynced-sales-*.csv',
        self::FILE_PREFIX.'support-*.zip',
    ];

    /**
     * Hours after the last copy before the next daily one is made.
     */
    public const DUE_AFTER_HOURS = 20;

    /**
     * @param  string|null  $connection  the database to copy; the app's own when null
     */
    public function __construct(protected ?string $connection = null) {}

    /**
     * The folder the copies go to: the one set on the Settings page, otherwise storage/app/backups.
     */
    public function folder(): string
    {
        $folder = Setting::valueOf('backup_folder');

        // A relative folder resolves differently for the window and the queue workers (audit A11), so it is not used.
        return filled($folder) && self::isAbsolutePath((string) $folder) ? rtrim((string) $folder, '\\/') : self::defaultFolder();
    }

    /**
     * The app's own backup folder, used when none is set on the Settings page.
     */
    public static function defaultFolder(): string
    {
        return storage_path('app/backups');
    }

    /**
     * Whether a path is absolute: on a drive (C:\ or C:/), a network share (\\server\share) or from the root (/).
     */
    public static function isAbsolutePath(string $path): bool
    {
        return preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $path) === 1;
    }

    /**
     * Remember a folder this class wrote to, so a fresh start also cleans it after the folder was changed (audit A10).
     */
    public function rememberFolder(?string $folder = null): void
    {
        $folder = rtrim($folder ?? $this->folder(), '\\/');
        $folders = self::rememberedFolders();

        if (! in_array($folder, $folders, true)) {
            Setting::put(self::FOLDERS_SETTING, json_encode([...$folders, $folder], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Every folder this class wrote to before.
     *
     * @return list<string>
     */
    public static function rememberedFolders(): array
    {
        $folders = json_decode((string) Setting::valueOf(self::FOLDERS_SETTING), true);

        return is_array($folders) ? array_values(array_filter($folders, fn (mixed $folder) => is_string($folder) && $folder !== '')) : [];
    }

    /**
     * The database copies in the backup folder, newest first.
     *
     * @return Collection<int, string>
     */
    public function backups(): Collection
    {
        return collect(File::glob($this->folder().DIRECTORY_SEPARATOR.self::FILE_PREFIX.'*.sqlite') ?: [])
            ->sortDesc()
            ->values();
    }

    /**
     * Make the daily copy when the last one is older than {@see self::DUE_AFTER_HOURS}.
     */
    public function runIfDue(): ?string
    {
        $latest = $this->backups()->first();

        if ($latest && Carbon::createFromTimestamp(File::lastModified($latest))->gt(now()->subHours(self::DUE_AFTER_HOURS))) {
            return null;
        }

        return $this->run();
    }

    /**
     * Copy the database now (SQLite's VACUUM INTO: a consistent copy while the till keeps working) and remove
     * the oldest copies. Returns the copy's path, or null when it could not be made.
     *
     * @param  string  $reason  added to the file name for a copy outside the daily one, e.g. "before-update"
     */
    public function run(string $reason = 'daily'): ?string
    {
        try {
            File::ensureDirectoryExists($this->folder());
            $this->rememberFolder();

            $path = $this->folder().DIRECTORY_SEPARATOR.self::FILE_PREFIX.now()->format('Ymd-His').($reason === 'daily' ? '' : '-'.$reason).'.sqlite';

            DB::connection($this->connection)->statement('VACUUM INTO ?', [$path]);

            $this->backups()->slice(self::KEEP)->each(fn (string $old) => File::delete($old));

            Log::info('Local backup: database copied', ['path' => $path]);

            return $path;
        } catch (Throwable $exception) {
            Log::warning('Local backup: the database could not be copied: '.$exception->getMessage());

            return null;
        }
    }

    /**
     * Write the sales that have not reached the ERP (with their items and payments) to the backup folder, as one
     * JSON file and one CSV file of the sales. Returns both paths.
     *
     * @return array{json: string, csv: string}
     */
    public function exportUnsynced(): array
    {
        File::ensureDirectoryExists($this->folder());
        $this->rememberFolder();

        $orders = Order::query()
            ->notDraft()
            ->whereNull('orders.last_sync_at')
            ->with(['orderItem', 'transaction', 'contact'])
            ->orderBy('orders.created_at')
            ->get();

        $base = $this->folder().DIRECTORY_SEPARATOR.self::FILE_PREFIX.'unsynced-sales-'.now()->format('Ymd-His');

        File::put($base.'.json', json_encode([
            'exported_at' => now()->toIso8601String(),
            'app_version' => AppVersion::current(),
            'orders' => $orders->map(fn (Order $order) => [
                ...$order->attributesToArray(),
                'contact' => $order->contact?->only(['id', 'name', 'mobile']),
                'items' => $order->orderItem->map->attributesToArray()->all(),
                'payments' => $order->transaction->map->attributesToArray()->all(),
            ])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $csv = fopen($base.'.csv', 'w');
        fputcsv($csv, ['code', 'created_at', 'customer', 'mobile', 'net_amount', 'paid_amount', 'due_amount', 'items', 'sync_error'], escape: '');

        foreach ($orders as $order) {
            fputcsv($csv, [
                $order->code,
                $order->created_at?->toDateTimeString(),
                $order->contact?->name,
                $order->contact?->mobile,
                $order->net_amount,
                $order->paid_amount,
                $order->due_amount,
                $order->orderItem->count(),
                $order->sync_response ? $order->syncErrorMessage() : null,
            ], escape: '');
        }

        fclose($csv);

        return ['json' => $base.'.json', 'csv' => $base.'.csv'];
    }

    /**
     * Remove every file this class wrote (a fresh-start activation): in the folder set now, the app's own folder and
     * every folder used before (audit A10). Only the files of {@see self::FILE_PATTERNS} go (audit A11).
     */
    public function deleteAll(): void
    {
        foreach (array_unique([$this->folder(), self::defaultFolder(), ...self::rememberedFolders()]) as $folder) {
            foreach (self::FILE_PATTERNS as $pattern) {
                foreach (File::glob(rtrim($folder, '\\/').DIRECTORY_SEPARATOR.$pattern) ?: [] as $file) {
                    File::delete($file);
                }
            }
        }
    }

    /**
     * Hours the oldest sale still waiting to upload has waited, or null when none waits.
     */
    public static function oldestWaitingHours(): ?int
    {
        $oldest = Order::syncPending()->min('orders.created_at');

        return $oldest ? (int) Carbon::parse($oldest)->diffInHours(now()) : null;
    }
}
