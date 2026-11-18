<?php

namespace Rishadblack\SyllooPosCore\Defaults;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Rishadblack\SyllooPosCore\Contracts\DrawerKick;
use Throwable;

/**
 * The cash drawer kick on Windows (the PC till, the default): Electron prints receipts as pages and can not send
 * printer commands, so the bytes go to the printer as a RAW job through the Windows print spooler (PowerShell and
 * winspool). Moved unchanged from Services\CashDrawer::open().
 */
class WindowsRawDrawerKick implements DrawerKick
{
    public function kick(array $bytes, ?string $printer): bool
    {
        try {
            $result = Process::timeout(15)
                ->env(['SYLLOO_DRAWER_PRINTER' => (string) $printer, 'SYLLOO_DRAWER_BYTES' => implode(',', $bytes)])
                ->run(['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', base64_encode(mb_convert_encoding(self::script(), 'UTF-16LE', 'UTF-8'))]);

            if (! $result->successful()) {
                Log::warning('Cash drawer: the printer did not take the kick', ['printer' => (string) $printer, 'output' => mb_substr($result->errorOutput() ?: $result->output(), 0, 500)]);
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
