<?php

use Rishadblack\SyllooPosCore\Support\Barcode\Code128Svg;

/*
 * The receipt's Code 128, drawn without a barcode package (no ext-gd on the Android till). The symbol values and check
 * symbols below are worked out by hand from the Code 128 table: start + Σ value × position, mod 103.
 */

it('draws a receipt number in code set B, with its digits in code set C', function () {
    // P O S, CODE C, 12 34, check (104 + 48 + 94 + 153 + 396 + 60 + 204 = 1059, mod 103 = 29), stop.
    expect(Code128Svg::symbols('POS1234'))->toBe([104, 48, 47, 51, 99, 12, 34, 29, 106]);
});

it('starts in code set C for a code of digits only', function () {
    // 105 + 12 + 34×2 + 56×3 = 353, mod 103 = 44.
    expect(Code128Svg::symbols('123456'))->toBe([105, 12, 34, 56, 44, 106]);
});

it('keeps the first digit of an odd run in code set B and the rest in pairs', function () {
    // 104 + 17 + 99×2 + 23×3 + 45×4 = 568, mod 103 = 53.
    expect(Code128Svg::symbols('12345'))->toBe([104, 17, 99, 23, 45, 53, 106]);
});

it('goes back to code set B after a run of digits', function () {
    // A, CODE C, 12 34, CODE B, Z: 104 + 33 + 99×2 + 12×3 + 34×4 + 100×5 + 58×6 = 1355, mod 103 = 16.
    expect(Code128Svg::symbols('A1234Z'))->toBe([104, 33, 99, 12, 34, 100, 58, 16, 106]);
});

it('keeps short runs of digits in code set B', function () {
    expect(Code128Svg::symbols('POS1'))->toBe([104, 48, 47, 51, 17, 55, 106])
        ->and(Code128Svg::symbols('A123'))->toBe([104, 33, 17, 18, 19, (104 + 33 + 34 + 54 + 76) % 103, 106]);
});

it('works out the check symbol from every data symbol and its position', function (string $code) {
    $symbols = Code128Svg::symbols($code);
    $data = array_slice($symbols, 1, -2);
    $sum = $symbols[0];

    foreach ($data as $index => $value) {
        $sum += $value * ($index + 1);
    }

    expect($symbols[count($symbols) - 2])->toBe($sum % 103)
        ->and(end($symbols))->toBe(106);
})->with(['POS1A2B3C4D', 'POS01KXYZ1234567', '0000', '9', 'a b~', 'POS20261118000123']);

it('gives null for a code it can not draw', function (string $code) {
    expect(Code128Svg::symbols($code))->toBeNull()
        ->and(Code128Svg::svg($code))->toBeNull();
})->with(['empty' => '', 'not ASCII' => "caf\u{e9}", 'Bangla digits' => '১২৩৪', 'control character' => "POS\t1"]);

it('draws the bars as an SVG of 11 modules a symbol and 13 for the stop', function () {
    $svg = Code128Svg::svg('POS1234', 1.0, 40);
    $symbols = Code128Svg::symbols('POS1234');
    $width = (count($symbols) - 1) * 11 + 13;

    expect($svg)->toStartWith('<?xml')
        ->toContain('<svg width="'.$width.'" height="40"')
        ->toContain('<rect x="0" y="0" width="2" height="40" fill="black"/>')
        ->not->toContain('<image')
        ->and(substr_count($svg, '<rect'))->toBe((count($symbols) - 1) * 3 + 4);
});
