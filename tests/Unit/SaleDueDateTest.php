<?php

use Illuminate\Support\Carbon;
use Rishadblack\SyllooPosCore\Services\SaleDueDate;

/*
 * A sale left partly or wholly due gets its due date by itself: the customer's credit term, else 30 days.
 */

it('gives a due sale 30 days when the customer has no credit term', function (?int $term) {
    expect(SaleDueDate::for(100.0, $term, '2026-11-18 21:45:00'))->toBe('2026-12-18')
        ->and(SaleDueDate::days($term))->toBe(30);
})->with(['none' => null, 'zero' => 0, 'below zero' => -5]);

it('gives a due sale the customer\'s credit term', function () {
    expect(SaleDueDate::for(0.01, 7, '2026-11-18'))->toBe('2026-11-25')
        ->and(SaleDueDate::for(5000.0, 45, Carbon::parse('2026-12-31 08:00')))->toBe('2027-02-14')
        ->and(SaleDueDate::days(45))->toBe(45);
});

it('gives no due date when nothing is owed', function (float $due) {
    expect(SaleDueDate::for($due, 7, '2026-11-18'))->toBeNull();
})->with(['paid' => 0.0, 'rounding noise' => 0.004, 'change given' => -20.0]);
