<?php

namespace Rishadblack\SyllooPosCore\Services;

use Rishadblack\SyllooPosCore\Models\Vat;

/**
 * Works out a sale's amounts exactly like the ERP does (its OrderCalculator and VatMath), so the
 * till, the receipt and the server show the same numbers:
 *
 * 1. A line is `quantity` × `price` (2 decimals), the price entered inclusive or exclusive of its VAT rate.
 * 2. The subtotal is the sum of the lines without their tax.
 * 3. The order discount is a percentage of the subtotal ("10%") or an amount, at most the subtotal,
 *    spread over the lines by their value without tax (the last line takes the rounding).
 * 4. Each line's tax is worked out on its value after its share of the discount (the ERP setting
 *    "VAT on the discounted amount", the default) or before it.
 * 5. The charge is split over the rates of the lines in proportion to the lines' value after discount (a share entered as
 *    its rate says: VAT on top, or inside it); its VAT is part of the tax.
 * 6. The net amount is the subtotal − the discount + the tax + the charge without VAT. `charges` stays the charge as
 *    entered, which the till pushes: the server works its VAT out again.
 */
class OrderCalculator
{
    /**
     * The ERP setting, as the last heartbeat reported it (on by default).
     */
    public static function vatAfterDiscount(): bool
    {
        return (bool) (app(SyncStatusService::class)->serverHealth()['vat_after_discount'] ?? true);
    }

    /**
     * @param  array<int|string, array{quantity?: mixed, price?: mixed, vat_type?: ?int, vat_percentage?: ?float}>  $lines
     * @return array{lines: array<int|string, array<string, mixed>>, subtotal: float, discount_amount: float, vat_amount: float, charges: float, charge_vat_amount: float, charge_vat_included: float, net_amount: float}
     */
    public function calculate(array $lines, mixed $discount, float $charges = 0.0, ?bool $vatAfterDiscount = null): array
    {
        $vatAfterDiscount ??= self::vatAfterDiscount();
        $subtotal = 0.0;

        foreach ($lines as $key => $line) {
            $vatType = isset($line['vat_type']) ? (int) $line['vat_type'] : null;
            $percentage = max(0.0, (float) ($line['vat_percentage'] ?? 0));
            $gross = round((float) ($line['quantity'] ?? 0) * (float) ($line['price'] ?? 0), 2);
            $withoutTax = self::withoutTax($gross, $vatType, $percentage);

            $lines[$key] = [
                ...$line,
                'vat_type' => $vatType,
                'vat_percentage' => $percentage,
                'subtotal' => $gross,
                'ex_vat_subtotal' => $withoutTax,
            ];
            $subtotal += $withoutTax;
        }

        $subtotal = round($subtotal, 2);
        $discountAmount = $this->discountAmount($discount, $subtotal);
        $shares = $this->spread($discountAmount, array_map(fn (array $line) => $line['ex_vat_subtotal'], $lines));
        $tax = 0.0;

        foreach ($lines as $key => $line) {
            $share = $shares[$key] ?? 0.0;
            $lineTax = self::taxOn($vatAfterDiscount ? $line['ex_vat_subtotal'] - $share : $line['ex_vat_subtotal'], $line['vat_percentage']);

            $lines[$key]['discount_amount'] = $share;
            $lines[$key]['vat_amount'] = $lineTax;
            $tax += $lineTax;
        }

        $charges = round(max(0, $charges), 2);
        [$chargeAmount, $chargeVat] = $this->splitCharge($charges, $lines);
        $tax = round($tax + $chargeVat, 2);

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'vat_amount' => $tax,
            'charges' => $charges,
            'charge_vat_amount' => $chargeVat,
            'charge_vat_included' => round($charges - $chargeAmount, 2),
            'net_amount' => round($subtotal - $discountAmount + $tax + $chargeAmount, 2),
        ];
    }

    /**
     * The charge without VAT and the VAT on it: split over the rates of the lines in proportion to their value after the
     * discount (else before it, else alike), the last rate taking the rounding, like the ERP's splitCharges().
     *
     * @param  array<int|string, array<string, mixed>>  $lines  worked-out lines
     * @return array{0: float, 1: float}
     */
    private function splitCharge(float $charge, array $lines): array
    {
        if ($charge <= 0 || $lines === []) {
            return [$charge, 0.0];
        }

        $positive = fn (array $values) => array_filter($values, fn (float $value) => $value > 0);
        $weights = $positive(array_map(fn (array $line) => (float) $line['ex_vat_subtotal'] - (float) $line['discount_amount'], $lines))
            ?: $positive(array_map(fn (array $line) => (float) $line['ex_vat_subtotal'], $lines))
            ?: array_map(fn () => 1.0, $lines);

        $rates = [];

        foreach ($weights as $key => $weight) {
            $percentage = (float) $lines[$key]['vat_percentage'];
            $rate = $percentage > 0 ? ($lines[$key]['vat_type'] === Vat::INCLUSIVE ? 'in' : 'on').'|'.$percentage : 'none';
            $rates[$rate] = [
                'type' => $lines[$key]['vat_type'],
                'percentage' => max(0.0, $percentage),
                'weight' => ($rates[$rate]['weight'] ?? 0.0) + $weight,
            ];
        }

        $shares = $this->spread($charge, array_map(fn (array $rate) => $rate['weight'], $rates));
        $amount = 0.0;
        $vat = 0.0;

        foreach ($rates as $rate => $group) {
            $net = self::withoutTax($shares[$rate], $group['type'], $group['percentage']);
            $amount += $net;
            $vat += $group['type'] === Vat::INCLUSIVE ? round($shares[$rate] - $net, 2) : self::taxOn($net, $group['percentage']);
        }

        return [round($amount, 2), round($vat, 2)];
    }

    /**
     * "10%" is a percentage of the subtotal, a number an amount; never below zero or above the subtotal.
     */
    public function discountAmount(mixed $discount, float $subtotal): float
    {
        $discount = trim((string) ($discount ?? ''));

        if ($discount === '') {
            return 0.0;
        }

        $amount = str_contains($discount, '%')
            ? $subtotal * (float) str_replace('%', '', $discount) / 100
            : (float) $discount;

        return round(min(max($amount, 0), $subtotal), 2);
    }

    /**
     * The amount without its tax: an inclusive amount less the tax inside it, an exclusive amount as it is.
     */
    public static function withoutTax(float $amount, ?int $vatType, float $percentage): float
    {
        if ($percentage <= 0 || $vatType !== Vat::INCLUSIVE) {
            return round($amount, 2);
        }

        return round($amount / (1 + $percentage / 100), 2);
    }

    /**
     * The tax on an amount without tax.
     */
    public static function taxOn(float $amountWithoutTax, float $percentage): float
    {
        return round($amountWithoutTax * max(0.0, $percentage) / 100, 2);
    }

    /**
     * Spreads an amount over the given values in proportion; the shares add up to the amount exactly.
     *
     * @param  array<int|string, float>  $values
     * @return array<int|string, float>
     */
    private function spread(float $amount, array $values): array
    {
        $total = array_sum($values);

        if ($amount <= 0 || $total <= 0) {
            return array_map(fn () => 0.0, $values);
        }

        $shares = [];
        $given = 0.0;
        $lastKey = array_key_last($values);

        foreach ($values as $key => $value) {
            if ($key === $lastKey) {
                $shares[$key] = round($amount - $given, 2);

                continue;
            }

            $shares[$key] = round($amount * $value / $total, 2);
            $given += $shares[$key];
        }

        return $shares;
    }
}
