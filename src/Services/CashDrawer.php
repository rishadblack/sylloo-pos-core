<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Log;
use Rishadblack\SyllooPosCore\Contracts\DrawerKick;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Setting;
use Throwable;

/**
 * The cash drawer on the receipt printer's drawer port (audit Phase 3, drawer kick): when it opens, and the kick
 * (ESC p 0 25 250) handed to the till's DrawerKick as raw bytes (the PC till: the Windows print spooler,
 * Defaults\WindowsRawDrawerKick). Off unless the shop sets it (Settings): a printer without a drawer would print the
 * bytes as text.
 */
class CashDrawer
{
    public const SETTING = 'cash_drawer';

    /** @var array<string, string> */
    public const MODES = [
        'off' => 'No cash drawer',
        'cash' => 'Open for cash sales and cash in or out',
        'every' => 'Open for every sale',
    ];

    /** ESC p 0 25 250: pulse pin 2 for 50 ms on, 500 ms off. */
    public const KICK = [27, 112, 0, 25, 250];

    public static function mode(): string
    {
        $mode = (string) Setting::valueOf(self::SETTING);

        return array_key_exists($mode, self::MODES) ? $mode : 'off';
    }

    public static function isOn(): bool
    {
        return self::mode() !== 'off';
    }

    /**
     * Whether a saved sale opens the drawer: every sale, or only one paid (or given change) in cash.
     */
    public function opensFor(Order $order): bool
    {
        return match (self::mode()) {
            'every' => true,
            'cash' => (float) $order->pay_change_amount > 0 || $order->transaction()->with('paymentMethod')->get()->contains(fn ($transaction) => (bool) $transaction->paymentMethod?->isCash()),
            default => false,
        };
    }

    /**
     * Send the kick to the receipt printer (the system default when none is chosen) through the till's DrawerKick.
     * Never throws; returns whether the printer took it.
     */
    public function open(): bool
    {
        if (! self::isOn()) {
            return false;
        }

        try {
            $printer = (string) (app(ReceiptPrintService::class)->settings()['printer'] ?? '');

            return app(DrawerKick::class)->kick(self::KICK, $printer !== '' ? $printer : null);
        } catch (Throwable $exception) {
            Log::warning('Cash drawer: '.$exception->getMessage());

            return false;
        }
    }
}
