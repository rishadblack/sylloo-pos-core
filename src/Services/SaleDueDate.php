<?php

namespace Rishadblack\SyllooPosCore\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The due date a sale paid partly or wholly as Baki (due) gets by itself (owner decision 2026-11-18,
 * android-pos-plan.md §0): the sale's date plus the customer's credit term in days when the customer has one, else
 * plus 30 days. Plain values in and out, so every till's sale screen uses the same rule.
 *
 * The till keeps no credit term of its own yet (the pull sends none), so a till passes null until the ERP sends it.
 */
class SaleDueDate
{
    /** Days a due sale has when the customer has no credit term. */
    public const DEFAULT_DAYS = 30;

    /** Amounts below half a cent are rounding noise, not money owed. */
    private const MONEY_EPSILON = 0.005;

    /**
     * The days the customer has to pay: their credit term when it is more than 0, else {@see self::DEFAULT_DAYS}.
     */
    public static function days(?int $creditTermDays): int
    {
        return $creditTermDays !== null && $creditTermDays > 0 ? $creditTermDays : self::DEFAULT_DAYS;
    }

    /**
     * The due date (`Y-m-d`) of a sale that leaves `$amountDue` owing, or null when nothing is owed.
     */
    public static function for(float $amountDue, ?int $creditTermDays, CarbonInterface|string $saleDate): ?string
    {
        if ($amountDue < self::MONEY_EPSILON) {
            return null;
        }

        return CarbonImmutable::parse($saleDate)->startOfDay()->addDays(self::days($creditTermDays))->toDateString();
    }
}
