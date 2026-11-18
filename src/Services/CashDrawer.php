<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\Setting;
use Throwable;

/**
 * The cash drawer on the receipt printer's drawer port (audit Phase 3, drawer kick). Electron prints receipts as pages
 * and can not send printer commands, so the kick (ESC p 0 25 250) goes to the printer as raw bytes through the Windows
 * print spooler. Off unless the shop sets it (Settings): a printer without a drawer would print the bytes as text.
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
     * Send the kick to the receipt printer (the system default when none is chosen). Never throws; returns whether
     * the printer took it.
     */
    public function open(): bool
    {
        if (! self::isOn()) {
            return false;
        }

        try {
            $printer = (string) (app(ReceiptPrintService::class)->settings()['printer'] ?? '');
            $result = Process::timeout(15)
                ->env(['SYLLOO_DRAWER_PRINTER' => $printer, 'SYLLOO_DRAWER_BYTES' => implode(',', self::KICK)])
                ->run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', base64_encode(mb_convert_encoding(self::script(), 'UTF-16LE', 'UTF-8'))]);

            if (! $result->successful()) {
                Log::warning('Cash drawer: the printer did not take the kick', ['printer' => $printer, 'output' => mb_substr($result->errorOutput() ?: $result->output(), 0, 500)]);
            }

            return $result->successful();
        } catch (Throwable $exception) {
            Log::warning('Cash drawer: '.$exception->getMessage());

            return false;
        }
    }

    /**
     * PowerShell that writes the bytes to the printer as a RAW job (winspool). The printer name and the bytes come in
     * through the environment, never in the script.
     */
    protected static function script(): string
    {
        return <<<'PS'
$ErrorActionPreference = 'Stop'
$printer = $env:SYLLOO_DRAWER_PRINTER
if (-not $printer) { $printer = (Get-CimInstance Win32_Printer | Where-Object Default | Select-Object -First 1).Name }
if (-not $printer) { exit 2 }
Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public class SyllooRawPrint {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DocInfo { public string pDocName; public string pOutputFile; public string pDataType; }
    [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)] public static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError = true)] public static extern bool ClosePrinter(IntPtr handle);
    [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)] public static extern int StartDocPrinter(IntPtr handle, int level, [In] DocInfo info);
    [DllImport("winspool.drv", SetLastError = true)] public static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] public static extern bool StartPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] public static extern bool EndPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)] public static extern bool WritePrinter(IntPtr handle, byte[] bytes, int count, out int written);
    public static bool Send(string printer, byte[] bytes) {
        IntPtr handle;
        if (!OpenPrinter(printer, out handle, IntPtr.Zero)) { return false; }
        try {
            if (StartDocPrinter(handle, 1, new DocInfo { pDocName = "Cash drawer", pDataType = "RAW" }) == 0) { return false; }
            StartPagePrinter(handle);
            int written;
            bool sent = WritePrinter(handle, bytes, bytes.Length, out written);
            EndPagePrinter(handle);
            EndDocPrinter(handle);
            return sent && written == bytes.Length;
        } finally { ClosePrinter(handle); }
    }
}
"@
$bytes = [byte[]]($env:SYLLOO_DRAWER_BYTES -split ',' | ForEach-Object { [byte]$_ })
if (-not [SyllooRawPrint]::Send($printer, $bytes)) { exit 1 }
PS;
    }
}
