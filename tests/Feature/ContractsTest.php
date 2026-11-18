<?php

use Illuminate\Support\Facades\File;
use Rishadblack\SyllooAppBase\Contracts\Notifier;
use Rishadblack\SyllooAppBase\Device\StateRegistry;
use Rishadblack\SyllooAppBase\Screens\Hooks;
use Rishadblack\SyllooPosCore\Contracts\CustomerDisplayScreen;
use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;
use Rishadblack\SyllooPosCore\Contracts\ReceiptPrinter;
use Rishadblack\SyllooPosCore\Contracts\TillScreenFace;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Screens\TillScreens;
use Rishadblack\SyllooPosCore\Services\CustomerDisplayService;
use Rishadblack\SyllooPosCore\Services\LocalDataReset;
use Rishadblack\SyllooPosCore\Services\ReceiptPrintService;

/**
 * A printer that keeps what it was given.
 */
function recordingPrinter(array $printers = ['EPSON' => 'Epson TM-T20']): ReceiptPrinter
{
    $printer = new class($printers) implements ReceiptPrinter
    {
        /** @var list<array{html: string, printer: ?string, settings: array<string, mixed>}> */
        public array $printed = [];

        public function __construct(protected array $installed) {}

        public function print(string $html, ?string $printer, array $settings): void
        {
            $this->printed[] = compact('html', 'printer', 'settings');
        }

        public function printers(): array
        {
            return $this->installed;
        }
    };

    app()->instance(ReceiptPrinter::class, $printer);

    return $printer;
}

it('hands a self-contained receipt page to the till printer', function () {
    $printer = recordingPrinter();
    $receipts = app(ReceiptPrintService::class);

    $sent = $receipts->print($receipts->sampleOrder(), [...$receipts->settings(), 'printer' => 'EPSON', 'copies' => 2]);

    expect($sent)->toBeTrue()
        ->and($printer->printed)->toHaveCount(1)
        ->and($printer->printed[0]['printer'])->toBe('EPSON')
        ->and($printer->printed[0]['settings']['copies'])->toBe(2)
        ->and($printer->printed[0]['html'])->toContain('<html')
        ->and($printer->printed[0]['html'])->toContain('Content-Security-Policy')
        ->and($printer->printed[0]['html'])->not->toMatch('/<(script|link)\b|src="https?:/i');
});

it('prints on the system default printer when none is chosen', function () {
    $printer = recordingPrinter();
    $receipts = app(ReceiptPrintService::class);

    $receipts->print($receipts->sampleOrder(), [...$receipts->settings(), 'printer' => '']);

    expect($printer->printed[0]['printer'])->toBeNull();
});

it('never throws when the receipt can not be printed', function () {
    $receipts = app(ReceiptPrintService::class);

    expect($receipts->print($receipts->sampleOrder()))->toBeFalse();
});

it('lists the till printers, and none when they can not be listed', function () {
    recordingPrinter(['EPSON' => 'Epson TM-T20']);
    expect(app(ReceiptPrintService::class)->printers())->toBe(['EPSON' => 'Epson TM-T20']);

    app()->instance(ReceiptPrinter::class, new class implements ReceiptPrinter
    {
        public function print(string $html, ?string $printer, array $settings): void {}

        public function printers(): array
        {
            throw new RuntimeException('no spooler');
        }
    });
    expect(app(ReceiptPrintService::class)->printers())->toBe([]);
});

it('hands what the customer display shows to the till display', function () {
    $this->withoutDefer();
    $screen = new class implements CustomerDisplayScreen
    {
        public array $shown = [];

        public int $opened = 0;

        public function show(array $snapshot): void
        {
            $this->shown[] = $snapshot;
        }

        public function open(): void
        {
            $this->opened++;
        }

        public function advertisementUrl(string $file): string
        {
            return 'https://till.test/ads/'.$file;
        }
    };
    app()->instance(CustomerDisplayScreen::class, $screen);

    CustomerDisplayService::publishCart(['code' => 'S-1', 'customer' => null, 'stage' => 'cart', 'items' => [['name' => 'Tea', 'quantity' => 1.0, 'price' => 20.0, 'total' => 20.0]], 'totals' => ['total' => 20.0]]);
    app(CustomerDisplayService::class)->openWindow();

    File::put(app(CustomerDisplayService::class)->advertisementsPath().'/a.png', 'png');

    expect($screen->shown)->toHaveCount(1)
        ->and($screen->shown[0]['state'])->toBe('active')
        ->and($screen->opened)->toBe(1)
        ->and(app(CustomerDisplayService::class)->slides()->first())->toBe(['type' => 'image', 'src' => 'https://till.test/ads/a.png']);

    File::deleteDirectory(app(CustomerDisplayService::class)->advertisementsPath());
});

it('a fresh start stops the workers, empties the tables and removes the till folders it names', function () {
    $workers = new class implements QueueWorkers
    {
        public array $calls = [];

        public function up(string $queue): void
        {
            $this->calls[] = "up:$queue";
        }

        public function down(string $queue): void
        {
            $this->calls[] = "down:$queue";
        }
    };
    app()->instance(QueueWorkers::class, $workers);
    config(['sylloo-pos-core.reset.storage_folders' => ['framework/till-cache']]);
    File::ensureDirectoryExists(storage_path('framework/till-cache'));
    User::factory()->create();

    app(LocalDataReset::class)->handle();

    expect($workers->calls)->toBe(['down:default', 'down:images'])
        ->and(User::query()->count())->toBe(0)
        ->and(File::isDirectory(storage_path('framework/till-cache')))->toBeFalse();
});

it('tells the cashier through the till notifier when the ERP refuses an order', function () {
    $notifier = new class implements Notifier
    {
        public array $notices = [];

        public function notify(string $title, string $message, ?string $reference = null): void
        {
            $this->notices[] = $title;
        }
    };
    app()->instance(Notifier::class, $notifier);

    $job = new PushErpJob;
    $order = Order::query()->forceCreate(['code' => 'S-1', 'contact_id' => '01KCONTACT00000000000000000', 'desktop_pos_id' => '01KTERMINAL0000000000000000', 'type' => 1, 'invoice_type' => 1, 'sync_response' => null]);
    $notify = (fn ($orders, $ids) => $this->notifyNewlyRejected($orders, $ids));
    $notify->call($job, collect([$order]), [(string) $order->id]);

    expect($notifier->notices)->toBe(['Order not accepted']);
});

it('registers the till parts of the shared screens and leaves the face its own pieces', function () {
    $face = new class implements TillScreenFace
    {
        public array $notices = [];

        public bool $piecesAdded = false;

        public function signIn(User $cashier): void {}

        public function noticeOnNextPage(string $title, ?string $message = null, string $type = 'success'): void
        {
            $this->notices[] = $title;
        }

        public function addPieces(Hooks $hooks): void
        {
            $this->piecesAdded = true;
        }
    };

    TillScreens::register(app(Hooks::class), app(StateRegistry::class), $face);

    expect($face->piecesAdded)->toBeTrue()
        ->and(TillScreens::hasTerminal(['terminal' => ['desktop_pos_id' => 7]]))->toBeTrue()
        ->and(TillScreens::hasTerminal(['terminal' => ['desktop_pos_id' => '']]))->toBeFalse()
        ->and(TillScreens::hasTerminal([]))->toBeFalse()
        ->and(TillScreens::requiresLocalReset('1111222233334444'))->toBeFalse();

    User::factory()->create();
    Order::query()->forceCreate(['code' => 'S-1', 'contact_id' => '01KCONTACT00000000000000000', 'desktop_pos_id' => '01KTERMINAL0000000000000000', 'type' => 1, 'invoice_type' => 1]);
    expect(TillScreens::requiresLocalReset('1111222233334444'))->toBeTrue();
});
