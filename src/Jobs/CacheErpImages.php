<?php

namespace Rishadblack\SyllooPosCore\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Models\Brand;
use Rishadblack\SyllooPosCore\Models\Category;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\ShippingMethod;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\ImageCache;
use Rishadblack\SyllooPosCore\Support\TillQueues;

/**
 * Downloads the ERP images of synced records into the local cache (see {@see ImageCache}) and
 * stores each copy's path on its record, so pulls stay fast and images show offline.
 *
 * With a model and ids it handles those records (queued by {@see PullErpJob} after each page and
 * by the sign-in); without, it goes over every record and removes copies nothing uses any more.
 */
class CacheErpImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Models with images from the ERP.
     *
     * @var list<class-string<Model>>
     */
    public const MODELS = [
        Brand::class,
        Category::class,
        Product::class,
        PaymentMethod::class,
        ShippingMethod::class,
        User::class,
    ];

    public int $tries = 1;

    public int $timeout = 1800;

    /**
     * @param  class-string<Model>|null  $modelClass
     * @param  list<string>|null  $ids
     */
    /**
     * The queue of the image downloads: a worker of its own (see config/nativephp.php), so a slow download never holds up a sale.
     */
    public const QUEUE = 'images';

    public function __construct(public ?string $modelClass = null, public ?array $ids = null)
    {
        $this->onQueue(TillQueues::name(TillQueues::IMAGES));
    }

    public static function supports(string $modelClass): bool
    {
        return in_array($modelClass, self::MODELS, true);
    }

    public function handle(ImageCache $imageCache): void
    {
        if ($this->modelClass !== null) {
            if (self::supports($this->modelClass)) {
                $this->cacheRecords($imageCache, $this->modelClass, $this->ids);
            }

            return;
        }

        foreach (self::MODELS as $modelClass) {
            if (! $this->cacheRecords($imageCache, $modelClass, null)) {
                Log::info('Image cache: the ERP is not reachable; the remaining images wait for the next run');

                return;
            }
        }

        $this->pruneUnused($imageCache);
    }

    /**
     * Cache the images of the given records (every record when null). False when the ERP went
     * offline on the way, so the rest is left for later.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<string>|null  $ids
     */
    protected function cacheRecords(ImageCache $imageCache, string $modelClass, ?array $ids): bool
    {
        $model = new $modelClass;
        $query = $modelClass::query()->select([$model->getKeyName(), ...array_keys($modelClass::cachedImages()), ...array_keys($modelClass::cachedImageLists()), ...$modelClass::cachedImageColumns()]);

        if ($ids !== null) {
            $query->whereIn($model->qualifyColumn($model->getKeyName()), $ids);
        }

        foreach ($query->lazyById(200, $model->getKeyName()) as $record) {
            $this->cacheRecord($imageCache, $record);

            if ($imageCache->isOffline()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cache one record's images and keep the local paths on it. A failed download keeps the copy
     * the record had, so an older image still shows offline; the views fall back to the ERP URL
     * when there is none.
     */
    public function cacheRecord(ImageCache $imageCache, Model $record): void
    {
        $changes = [];
        $replaced = [];

        foreach ($record::cachedImages() as $source => $target) {
            $url = $record->getRawOriginal($source);
            $current = $record->getRawOriginal($target);

            $path = ImageCache::isRemote($url) ? $imageCache->cache($url) : null;

            if ($path === null && ImageCache::isRemote($url)) {
                continue;
            }

            if ($path !== $current) {
                $changes[$target] = $path;
                $replaced[] = $current;
            }
        }

        foreach ($record::cachedImageLists() as $source => $target) {
            $urls = array_values(array_filter((array) json_decode((string) $record->getRawOriginal($source), true), 'is_string'));
            $current = array_values((array) json_decode((string) $record->getRawOriginal($target), true));

            $paths = [];
            foreach ($urls as $index => $url) {
                $paths[] = $imageCache->cache($url) ?? ($current[$index] ?? null);
            }

            $paths = array_values(array_filter($paths));

            if ($paths !== $current) {
                $changes[$target] = $paths === [] ? null : json_encode($paths);
                $replaced = [...$replaced, ...array_diff($current, $paths)];
            }
        }

        if ($changes === []) {
            return;
        }

        DB::table($record->getTable())->where($record->getKeyName(), $record->getKey())->update($changes);

        foreach (array_unique(array_filter($replaced)) as $oldPath) {
            if (! $this->isUsed($oldPath)) {
                $imageCache->delete($oldPath);
            }
        }
    }

    /**
     * True when a record still shows this cached copy (records may share an image, e.g. variants).
     */
    protected function isUsed(string $path): bool
    {
        foreach (self::MODELS as $modelClass) {
            $table = (new $modelClass)->getTable();

            foreach ($modelClass::cachedImages() as $target) {
                if (DB::table($table)->where("{$table}.{$target}", $path)->exists()) {
                    return true;
                }
            }

            foreach ($modelClass::cachedImageLists() as $target) {
                if (DB::table($table)->where("{$table}.{$target}", 'like', '%'.basename($path).'%')->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Remove the copies no live record shows (images replaced or removed on the ERP, deleted records).
     */
    protected function pruneUnused(ImageCache $imageCache): void
    {
        $used = [];

        foreach (self::MODELS as $modelClass) {
            $table = (new $modelClass)->getTable();
            $query = DB::table($table);

            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $query->whereNull("{$table}.deleted_at");
            }

            foreach ($modelClass::cachedImages() as $target) {
                foreach ((clone $query)->whereNotNull("{$table}.{$target}")->pluck($target) as $path) {
                    $used[$path] = true;
                }
            }

            foreach ($modelClass::cachedImageLists() as $target) {
                foreach ((clone $query)->whereNotNull("{$table}.{$target}")->pluck($target) as $paths) {
                    foreach ((array) json_decode((string) $paths, true) as $path) {
                        $used[$path] = true;
                    }
                }
            }
        }

        // A small copy stays while its image does (ImageCache::thumbnail()).
        foreach (array_keys($used) as $path) {
            $used[ImageCache::thumbPathOf((string) $path)] = true;
        }

        foreach ($imageCache->files() as $file) {
            if (! isset($used[$file])) {
                $imageCache->delete($file);
            }
        }
    }
}
