<?php

namespace Rishadblack\SyllooPosCore;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Translation\FileLoader;
use Rishadblack\SyllooAppBase\Config\RemoteConfig as BaseRemoteConfig;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;
use Rishadblack\SyllooAppBase\Contracts\MemberDirectory;
use Rishadblack\SyllooAppBase\Contracts\ReleaseSteps;
use Rishadblack\SyllooAppBase\Device\DeviceState as BaseDeviceState;
use Rishadblack\SyllooAppBase\Images\ImageCache as BaseImageCache;
use Rishadblack\SyllooAppBase\Pin\PinSignIn as BasePinSignIn;
use Rishadblack\SyllooAppBase\Release\DeviceRelease as BaseDeviceRelease;
use Rishadblack\SyllooAppBase\Update\AppUpdateService as BaseAppUpdateService;
use Rishadblack\SyllooPosCore\Contracts\CustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Contracts\DrawerKick;
use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;
use Rishadblack\SyllooPosCore\Contracts\ReceiptBarcode;
use Rishadblack\SyllooPosCore\Contracts\ReceiptPrinter;
use Rishadblack\SyllooPosCore\Contracts\TillSettings;
use Rishadblack\SyllooPosCore\Defaults\ArrayTillSettings;
use Rishadblack\SyllooPosCore\Defaults\Code128ReceiptBarcode;
use Rishadblack\SyllooPosCore\Defaults\NullCustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Defaults\NullQueueWorkers;
use Rishadblack\SyllooPosCore\Defaults\NullReceiptPrinter;
use Rishadblack\SyllooPosCore\Defaults\WindowsRawDrawerKick;
use Rishadblack\SyllooPosCore\Models\Product;
use Rishadblack\SyllooPosCore\Models\ProductPricing;
use Rishadblack\SyllooPosCore\Models\Unit;
use Rishadblack\SyllooPosCore\Models\Vat;
use Rishadblack\SyllooPosCore\Services\AppUpdateService;
use Rishadblack\SyllooPosCore\Services\CashierDirectory;
use Rishadblack\SyllooPosCore\Services\DeviceRelease;
use Rishadblack\SyllooPosCore\Services\ImageCache;
use Rishadblack\SyllooPosCore\Services\PinSignIn;
use Rishadblack\SyllooPosCore\Services\RemoteConfig;
use Rishadblack\SyllooPosCore\Services\TerminalReleaseSteps;
use Rishadblack\SyllooPosCore\Support\CartPriceCache;
use Rishadblack\SyllooPosCore\Support\TerminalDeviceState;

/**
 * The shared core of the Sylloo POS tills: config `sylloo-pos-core`, the till's migrations, the receipt views
 * (`sylloo-pos-core::receipts.*`) and the texts (`device.*` and the till's words on the shared base's screens,
 * `sylloo-app-base::device.*`), in English and Bangla; the till's answers to the shared base that every till shares.
 *
 * Each till binds its own platform in its own service provider: the base's DeviceStore, Notifier and AppUpdater, and
 * this package's Contracts\TillSettings, Contracts\QueueWorkers, Contracts\ReceiptPrinter,
 * Contracts\CustomerDisplayScreen and Contracts\DrawerKick (and Contracts\ReceiptBarcode to draw its own); and it
 * registers Screens\TillScreens with its Contracts\TillScreenFace. Unbound, the defaults keep settings in memory,
 * print, show and start nothing, draw receipts' Code 128 and kick the drawer through the Windows print spooler.
 */
class SyllooPosCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sylloo-pos-core.php', 'sylloo-pos-core');

        $this->app->bindIf(TillSettings::class, ArrayTillSettings::class, true);
        $this->app->bindIf(QueueWorkers::class, NullQueueWorkers::class, true);
        $this->app->bindIf(ReceiptPrinter::class, NullReceiptPrinter::class, true);
        $this->app->bindIf(CustomerDisplayScreen::class, NullCustomerDisplayScreen::class, true);
        $this->app->bindIf(ReceiptBarcode::class, Code128ReceiptBarcode::class, true);
        $this->app->bindIf(DrawerKick::class, WindowsRawDrawerKick::class, true);

        $this->answerTheSharedBase();

        $this->addTexts();
    }

    /**
     * The till's answers to the shared base that are the same on every till: the terminal's device state (its extra
     * settings and the "almost ready" state), the cashiers as the people, the release steps "send sales first, then
     * wipe", and the till's classes over the base's (so the base's own calls get the till's class, and a test's mock
     * of it). Where they are kept (DeviceStore) and how they are shown is each till's.
     */
    protected function answerTheSharedBase(): void
    {
        $this->app->singleton(BaseDeviceState::class, fn (Application $app) => new TerminalDeviceState($app->make(DeviceStore::class)));
        $this->app->bind(MemberDirectory::class, CashierDirectory::class);
        $this->app->bind(ReleaseSteps::class, TerminalReleaseSteps::class);

        $this->app->singleton(RemoteConfig::class);
        $this->app->alias(RemoteConfig::class, BaseRemoteConfig::class);
        $this->app->bind(BaseImageCache::class, fn (Application $app) => $app->make(ImageCache::class));
        $this->app->bind(BaseAppUpdateService::class, fn (Application $app) => $app->make(AppUpdateService::class));
        $this->app->bind(BasePinSignIn::class, fn (Application $app) => $app->make(PinSignIn::class));
        $this->app->bind(BaseDeviceRelease::class, fn (Application $app) => $app->make(DeviceRelease::class));
    }

    public function boot(): void
    {
        // Whatever prices the cart changed here: the cart's kept prices are read again (audit M2).
        foreach ([Product::class, ProductPricing::class, Vat::class, Unit::class] as $pricing) {
            $pricing::saved(fn () => CartPriceCache::forget());
            $pricing::deleted(fn () => CartPriceCache::forget());
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'sylloo-pos-core');

        if (config('sylloo-pos-core.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/sylloo-pos-core.php' => config_path('sylloo-pos-core.php')], 'sylloo-pos-core-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/sylloo-pos-core')], 'sylloo-pos-core-views');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'sylloo-pos-core-migrations');
        }
    }

    /**
     * The texts are the till's own groups (`__('device.…')`, as before the move) and its words on the base's screens
     * (`resources/lang/vendor/sylloo-app-base`). The package's folder is read after Laravel's own and before the
     * app's `lang/`, so an app's file of the same name still wins.
     */
    protected function addTexts(): void
    {
        $path = __DIR__.'/../resources/lang';

        $this->app->extend('translation.loader', function (mixed $loader, Application $app) use ($path) {
            if (! $loader instanceof FileLoader || $loader::class !== FileLoader::class || $loader->namespaces() !== [] || $loader->jsonPaths() !== []) {
                if (is_object($loader) && method_exists($loader, 'addPath')) {
                    $loader->addPath($path);
                }

                return $loader;
            }

            $paths = $loader->paths();
            array_splice($paths, max(count($paths) - 1, 0), 0, [$path]);

            return new FileLoader($app['files'], $paths);
        });
    }
}
