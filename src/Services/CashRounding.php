<?php

namespace Rishadblack\SyllooPosCore\Services;

/**
 * Cash rounding, worked out like the ERP's CashRounding (its docs/cash-rounding.md): a sale paid only in cash is rounded
 * to the business's step (for example 0.05 in Finland; 0 is off), at most half a step either way. The step comes with
 * the heartbeat (`settings.cash_rounding_step`) and the last one is kept for offline sales; the ERP checks the rounding
 * again when the sale is pushed.
 */
class CashRounding
{
    /**
     * The business's step as the last heartbeat reported it; 0 when off.
     */
    public static function step(): float
    {
        $step = round((float) (app(SyncStatusService::class)->serverHealth()['cash_rounding_step'] ?? 0), 2);

        return $step > 0 ? $step : 0.0;
    }

    /**
     * What the total is rounded by to reach the nearest multiple of the step (0 while the step is off).
     */
    public static function amountFor(float $netAmount, ?float $step = null): float
    {
        $step ??= self::step();

        if ($step <= 0) {
            return 0.0;
        }

        $rounded = round(round(round($netAmount, 2) / $step) * $step, 2);

        return round($rounded - round($netAmount, 2), 2);
    }
}
