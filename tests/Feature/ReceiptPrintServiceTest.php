<?php

use Illuminate\Support\Facades\File;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\ReceiptPrintService;

/*
 * Receipt parts that must work without fileinfo and without a signed-in user (the Android till).
 */

it('reads the logo type from its file name', function (string $file, ?string $type) {
    $folder = storage_path('app/public/receipt-test');
    File::ensureDirectoryExists($folder);
    File::put($folder.'/'.$file, 'logo-bytes');

    try {
        $logo = app(ReceiptPrintService::class)->printableLogo('receipt-test/'.$file);

        expect($logo)->toBe($type === null ? null : 'data:'.$type.';base64,'.base64_encode('logo-bytes'));
    } finally {
        File::deleteDirectory($folder);
    }
})->with([
    ['logo.png', 'image/png'],
    ['logo.JPG', 'image/jpeg'],
    ['logo.jpeg', 'image/jpeg'],
    ['logo.gif', 'image/gif'],
    ['logo.webp', 'image/webp'],
    ['logo.svg', 'image/svg+xml'],
    ['logo.txt', null],
]);

it('keeps a logo URL as it is and prints no missing file', function () {
    $receipts = app(ReceiptPrintService::class);

    expect($receipts->printableLogo('https://cdn.example/logo.png'))->toBe('https://cdn.example/logo.png')
        ->and($receipts->printableLogo('nowhere/logo.png'))->toBeNull()
        ->and($receipts->printableLogo(null))->toBeNull();
});

it('names the cashier given on the sample receipt', function () {
    $cashier = User::factory()->make(['name' => 'Rahim']);
    $receipts = app(ReceiptPrintService::class);

    expect($receipts->sampleOrder($cashier)->creator?->name)->toBe('Rahim')
        ->and($receipts->sampleOrder()->creator)->toBeNull();
});
