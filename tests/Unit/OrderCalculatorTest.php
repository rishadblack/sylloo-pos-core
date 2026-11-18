<?php

use Rishadblack\SyllooPosCore\Models\Vat;
use Rishadblack\SyllooPosCore\Services\OrderCalculator;

/**
 * Every expected number below is what the ERP's OrderCalculator gives for the same lines (worked out
 * by hand from its steps), so the till shows exactly the totals the server books.
 */
it('taxes exclusive VAT after a percent discount', function () {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 2, 'price' => 100, 'vat_type' => Vat::EXCLUSIVE, 'vat_percentage' => 5],
        ['quantity' => 1, 'price' => 50, 'vat_type' => Vat::EXCLUSIVE, 'vat_percentage' => 5],
    ], '10%', vatAfterDiscount: true);

    expect($amounts)->toMatchArray([
        'subtotal' => 250.0,
        'discount_amount' => 25.0,
        'vat_amount' => 11.25,
        'charges' => 0.0,
        'net_amount' => 236.25,
    ])
        ->and($amounts['lines'][0])->toMatchArray(['subtotal' => 200.0, 'ex_vat_subtotal' => 200.0, 'discount_amount' => 20.0, 'vat_amount' => 9.0])
        ->and($amounts['lines'][1])->toMatchArray(['subtotal' => 50.0, 'ex_vat_subtotal' => 50.0, 'discount_amount' => 5.0, 'vat_amount' => 2.25]);
});

it('taxes exclusive VAT before a percent discount when the ERP says so', function () {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 2, 'price' => 100, 'vat_type' => Vat::EXCLUSIVE, 'vat_percentage' => 5],
        ['quantity' => 1, 'price' => 50, 'vat_type' => Vat::EXCLUSIVE, 'vat_percentage' => 5],
    ], '10%', vatAfterDiscount: false);

    expect($amounts)->toMatchArray([
        'subtotal' => 250.0,
        'discount_amount' => 25.0,
        'vat_amount' => 12.5,
        'net_amount' => 237.5,
    ])
        ->and($amounts['lines'][0]['vat_amount'])->toBe(10.0)
        ->and($amounts['lines'][1]['vat_amount'])->toBe(2.5);
});

it('takes inclusive VAT out of the price and applies an amount discount', function () {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 3, 'price' => 115, 'vat_type' => Vat::INCLUSIVE, 'vat_percentage' => 15],
    ], '20', vatAfterDiscount: true);

    expect($amounts)->toMatchArray([
        'subtotal' => 300.0,
        'discount_amount' => 20.0,
        'vat_amount' => 42.0,
        'net_amount' => 322.0,
    ])
        ->and($amounts['lines'][0])->toMatchArray(['subtotal' => 345.0, 'ex_vat_subtotal' => 300.0, 'discount_amount' => 20.0, 'vat_amount' => 42.0]);
});

it('gives the rounding of the discount to the last line', function () {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 1, 'price' => 10, 'vat_type' => null, 'vat_percentage' => 0],
        ['quantity' => 1, 'price' => 10, 'vat_type' => null, 'vat_percentage' => 0],
        ['quantity' => 1, 'price' => 10, 'vat_type' => Vat::EXCLUSIVE, 'vat_percentage' => 5],
    ], '10', 15, vatAfterDiscount: true);

    expect(array_column($amounts['lines'], 'discount_amount'))->toBe([3.33, 3.33, 3.34])
        ->and(array_column($amounts['lines'], 'vat_amount'))->toBe([0.0, 0.0, 0.33])
        // The charge's share on the 5% line (15 × 6.66 / 20 = 5.00) carries 0.25 VAT on top, which is part of the tax.
        ->and($amounts)->toMatchArray([
            'subtotal' => 30.0,
            'discount_amount' => 10.0,
            'vat_amount' => 0.58,
            'charges' => 15.0,
            'charge_vat_amount' => 0.25,
            'net_amount' => 35.58,
        ]);
});

it('charges no VAT on lines without a rate', function () {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 3, 'price' => 12.5, 'vat_type' => null, 'vat_percentage' => 0],
    ], '5%', vatAfterDiscount: true);

    expect($amounts)->toMatchArray([
        'subtotal' => 37.5,
        'discount_amount' => 1.88,
        'vat_amount' => 0.0,
        'net_amount' => 35.62,
    ]);
});

it('never discounts more than the subtotal and ignores negative charges', function (mixed $discount, float $expected) {
    $amounts = (new OrderCalculator)->calculate([
        ['quantity' => 1, 'price' => 40, 'vat_type' => null, 'vat_percentage' => 0],
    ], $discount, -5, vatAfterDiscount: true);

    expect($amounts['discount_amount'])->toBe($expected)
        ->and($amounts['charges'])->toBe(0.0);
})->with([
    'amount above the subtotal' => ['100', 40.0],
    'percent above 100' => ['150%', 40.0],
    'negative amount' => ['-5', 0.0],
    'empty' => ['', 0.0],
    'none' => [null, 0.0],
]);
