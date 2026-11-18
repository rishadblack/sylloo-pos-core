<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Contracts\CustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;
use Rishadblack\SyllooPosCore\Contracts\ReceiptPrinter;
use Rishadblack\SyllooPosCore\Contracts\TillSettings;
use Rishadblack\SyllooPosCore\Defaults\ArrayTillSettings;
use Rishadblack\SyllooPosCore\Defaults\NullCustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Defaults\NullQueueWorkers;
use Rishadblack\SyllooPosCore\Defaults\NullReceiptPrinter;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Support\AppVersion;

it('runs the till migrations with the tables and columns of the PC till', function () {
    foreach (['orders', 'order_items', 'products', 'contacts', 'payment_methods', 'settings', 'shifts', 'pos_staff', 'sync_errors', 'kitchen_tickets', 'users'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('orders', ['code', 'last_sync_at', 'sync_response', 'invoice_type']))->toBeTrue();
});

it('seeds the default settings with the settings table', function () {
    expect(Setting::valueOf('timezone'))->not->toBeNull();
});

it('makes models from its own factories', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(User::class)
        ->and(Setting::factory()->make())->toBeInstanceOf(Setting::class);
});

it('reads the till texts without a namespace, also in Bangla', function () {
    expect(__('device.activated_title'))->not->toBe('device.activated_title')
        ->and(trans('device.activated_title', [], 'bn'))->not->toBe(__('device.activated_title'));
});

it('puts the till words on the shared base screens', function () {
    $till = require __DIR__.'/../../resources/lang/vendor/sylloo-app-base/en/device.php';
    $key = array_key_first($till);

    expect(__('sylloo-app-base::device.'.$key))->toBe(is_string($till[$key]) ? $till[$key] : __('sylloo-app-base::device.'.$key));
});

it('lets the app override a till text with its own lang file', function () {
    $folder = lang_path('en');
    File::ensureDirectoryExists($folder);
    File::put($folder.'/device.php', "<?php return ['activated_title' => 'Ready to sell'];");

    try {
        app()->forgetInstance('translator');
        app()->forgetInstance('translation.loader');

        expect(__('device.activated_title'))->toBe('Ready to sell');
    } finally {
        File::delete($folder.'/device.php');
    }
});

it('renders the receipt views from its own namespace', function () {
    expect(view()->exists('sylloo-pos-core::receipts.layouts.standard'))->toBeTrue()
        ->and(view()->exists('sylloo-pos-core::receipts.kitchen-ticket'))->toBeTrue()
        ->and(view()->exists('sylloo-pos-core::receipts.shift-report'))->toBeTrue()
        ->and(view()->exists('sylloo-pos-core::receipts.due-collection'))->toBeTrue();
});

it('answers every contract with a harmless default when the till binds none', function () {
    expect(app(TillSettings::class))->toBeInstanceOf(ArrayTillSettings::class)
        ->and(app(QueueWorkers::class))->toBeInstanceOf(NullQueueWorkers::class)
        ->and(app(ReceiptPrinter::class))->toBeInstanceOf(NullReceiptPrinter::class)
        ->and(app(CustomerDisplayScreen::class))->toBeInstanceOf(NullCustomerDisplayScreen::class)
        ->and(app(ReceiptPrinter::class)->printers())->toBe([]);
});

it('keeps settings through the facade on the bound store', function () {
    Settings::set('desktop_pos_id', '42');

    expect(Settings::get('desktop_pos_id'))->toBe('42')
        ->and(Settings::get('missing', 'fallback'))->toBe('fallback');

    Settings::forget('desktop_pos_id');
    expect(Settings::get('desktop_pos_id'))->toBeNull();

    Settings::set('a', 1);
    Settings::clear();
    expect(Settings::get('a'))->toBeNull();
});

it('reads the running version as the shared base does, without shortening it', function () {
    config(['sylloo-app-base.app.version' => null, 'nativephp.version' => '2.6.0']);
    expect(AppVersion::current())->toBe('2.6.0');

    config(['sylloo-app-base.app.version' => '3.0.0-beta.1+android.build.1234567890']);
    expect(AppVersion::current())->toBe('3.0.0-beta.1+android.build.1234567890');
});

it('names no platform, screen framework, session or request in its code', function () {
    $code = collect(File::allFiles(__DIR__.'/../../src'))
        ->merge(File::allFiles(__DIR__.'/../../database'))
        ->map(fn ($file) => [$file->getRelativePathname(), File::get($file->getPathname())]);

    foreach ($code as [$path, $source]) {
        expect($source)->not->toMatch('/^use (Native|Livewire|App)\\\\/m', $path)
            ->not->toMatch('/(?<![\w>])(session|request)\(/', $path)
            ->not->toContain('HtmlString', $path)
            ->not->toMatch('/\\\\(Native|Livewire)\\\\/', $path);
    }
});

it('needs no PHP extension the phone lacks and no barcode package', function () {
    $composer = json_decode((string) File::get(__DIR__.'/../../composer.json'), true);
    $required = array_keys($composer['require'] ?? []);

    expect($required)->not->toContain('ext-gd')
        ->not->toContain('ext-intl')
        ->not->toContain('ext-zip')
        ->and(array_filter($required, fn (string $package) => str_starts_with($package, 'milon/')))->toBe([]);
});
