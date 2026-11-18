<?php

use Rishadblack\SyllooPosCore\Contracts\ReceiptBarcode;
use Rishadblack\SyllooPosCore\Defaults\Code128ReceiptBarcode;
use Rishadblack\SyllooPosCore\Receipts\ReceiptOrderData;
use Rishadblack\SyllooPosCore\Services\ReceiptPrintService;
use Rishadblack\SyllooPosCore\Support\Barcode\Code128Svg;

/**
 * The barcode a receipt shows, as the receipt data gives it to the layout.
 */
function receiptBarcodeOf(string $code, string $type = 'C128'): ?string
{
    $order = app(ReceiptPrintService::class)->sampleOrder();
    $order->code = $code;

    return ReceiptOrderData::make($order)->barcodeType($type)->resolve()['barcode'];
}

it('draws the receipt barcode with the core Code 128 when the till binds none', function () {
    expect(app(ReceiptBarcode::class))->toBeInstanceOf(Code128ReceiptBarcode::class)
        ->and(receiptBarcodeOf('POS1234'))->toBe('data:image/svg+xml;base64,'.base64_encode(Code128Svg::svg('POS1234', 1.4, 40, 'black')));
});

it('draws any other barcode type as Code 128', function () {
    expect(receiptBarcodeOf('POS1234', 'EAN13'))->toBe(receiptBarcodeOf('POS1234'));
});

it('prints no barcode for a code Code 128 can not hold', function () {
    expect(receiptBarcodeOf("caf\u{e9}"))->toBeNull();
});

it('uses the barcode the till binds', function () {
    app()->instance(ReceiptBarcode::class, new class implements ReceiptBarcode
    {
        public function svg(string $code, string $type): ?string
        {
            return "<svg data-code=\"{$code}\" data-type=\"{$type}\"/>";
        }
    });

    expect(base64_decode(substr((string) receiptBarcodeOf('POS9', 'C128'), strlen('data:image/svg+xml;base64,'))))->toBe('<svg data-code="POS9" data-type="C128"/>');
});

it('puts the barcode on the printed receipt page', function () {
    $receipts = app(ReceiptPrintService::class);
    $html = $receipts->render($receipts->sampleOrder(), [...$receipts->settings(), 'show_barcode' => true]);

    expect($html)->toContain('class="barcode" src="data:image/svg+xml;base64,');
});
