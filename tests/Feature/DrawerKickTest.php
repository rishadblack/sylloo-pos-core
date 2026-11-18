<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Rishadblack\SyllooPosCore\Contracts\DrawerKick;
use Rishadblack\SyllooPosCore\Defaults\WindowsRawDrawerKick;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Services\CashDrawer;

/**
 * A drawer kick that keeps what it was sent.
 */
function recordingDrawerKick(bool $takes = true): object
{
    $kick = new class($takes) implements DrawerKick
    {
        /** @var list<array{bytes: list<int>, printer: ?string}> */
        public array $sent = [];

        public function __construct(protected bool $takes) {}

        public function kick(array $bytes, ?string $printer): bool
        {
            $this->sent[] = ['bytes' => $bytes, 'printer' => $printer];

            return $this->takes;
        }
    };

    app()->instance(DrawerKick::class, $kick);

    return $kick;
}

it('kicks the drawer through the Windows print spooler when the till binds nothing', function () {
    Process::fake();
    Setting::put(CashDrawer::SETTING, 'cash');

    expect(app(DrawerKick::class))->toBeInstanceOf(WindowsRawDrawerKick::class)
        ->and(app(CashDrawer::class)->open())->toBeTrue();

    Process::assertRan(fn (PendingProcess $process) => $process->command[0] === 'powershell'
        && $process->environment['SYLLOO_DRAWER_BYTES'] === '27,112,0,25,250'
        && $process->environment['SYLLOO_DRAWER_PRINTER'] === '');
});

it('hands the kick and the receipt printer to the till', function () {
    $kick = recordingDrawerKick();
    Setting::put(CashDrawer::SETTING, 'every');
    Setting::put('receipt_printer', 'EPSON');

    expect(app(CashDrawer::class)->open())->toBeTrue()
        ->and($kick->sent)->toBe([['bytes' => CashDrawer::KICK, 'printer' => 'EPSON']]);
});

it('sends no kick while the drawer is off, and says when the printer did not take it', function () {
    $kick = recordingDrawerKick(takes: false);

    expect(app(CashDrawer::class)->open())->toBeFalse()
        ->and($kick->sent)->toBe([]);

    Setting::put(CashDrawer::SETTING, 'cash');

    expect(app(CashDrawer::class)->open())->toBeFalse()
        ->and($kick->sent)->toHaveCount(1)
        ->and($kick->sent[0]['printer'])->toBeNull();
});
