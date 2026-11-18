<?php

namespace Rishadblack\SyllooPosCore\Tests;

use Illuminate\Support\ServiceProvider;
use Jantinnerezo\LivewireAlert\LivewireAlertServiceProvider;
use Livewire\LivewireServiceProvider;
use Milon\Barcode\BarcodeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;
use Rishadblack\SyllooAppBase\SyllooAppBaseServiceProvider;
use Rishadblack\SyllooAppBase\Testing\ArrayDeviceStore;
use Rishadblack\SyllooPosCore\SyllooPosCoreServiceProvider;
use Rishadblack\WireBootstrap\WireBootstrapServiceProvider;
use Rishadblack\WireModals\WireModalsServiceProvider;
use Spatie\Translatable\TranslatableServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            WireBootstrapServiceProvider::class,
            WireModalsServiceProvider::class,
            LivewireAlertServiceProvider::class,
            SyllooAppBaseServiceProvider::class,
            TranslatableServiceProvider::class,
            BarcodeServiceProvider::class,
            SyllooPosCoreServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('logging.default', 'null');
        $app['config']->set('nativephp.version', '2.6.0');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('sylloo-app-base.migrations', false);
        $app['config']->set('sylloo-app-base.routes.enabled', false);
        $app['config']->set('sylloo-app-base.app.name', 'Sylloo POS');

        // The till binds its own device store (the PC: NativePHP settings); here it is kept in memory.
        $app->singleton(DeviceStore::class, fn () => new ArrayDeviceStore);
    }
}
