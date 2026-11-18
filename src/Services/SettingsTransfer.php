<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Support\AppVersion;
use Rishadblack\SyllooPosCore\Support\IdleMinutes;
use Rishadblack\SyllooPosCore\Support\KitchenStations;
use Rishadblack\SyllooPosCore\Support\ScaleBarcode;

/**
 * Export the till's own settings to a JSON file and import them on another till (audit §7.3): set up one terminal,
 * copy its receipt and printer settings to the next, or restore them after a reinstall. The file holds only what the
 * till owns (receipt layout and options, printing, how long sales are kept); never the store details from the ERP,
 * the licence, cashier tokens or sales, so it is safe to email.
 */
class SettingsTransfer
{
    public const FORMAT = 'sylloo-pos-settings';

    public const FORMAT_VERSION = 1;

    public function __construct(protected ReceiptPrintService $receipts, protected TerminalProfile $profile) {}

    /**
     * The rules each value must pass, as on the Settings page. A value the file holds may not be empty (audit A11):
     * "" would be stored as null or 0, which the Settings page itself refuses.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'receipt.layout' => ['required', 'in:'.implode(',', array_keys(ReceiptPrintService::LAYOUTS))],
            'receipt.paper_width' => ['required', 'in:outlet,58,80'],
            'receipt.font_size' => ['required', 'in:normal,large'],
            'receipt.header_text' => ['nullable', 'string', 'max:100'],
            'receipt.footer_text' => ['nullable', 'string', 'max:500'],
            ...collect(ReceiptPrintService::switchKeys())->mapWithKeys(fn (string $key) => ["receipt.{$key}" => ['required', 'boolean']])->all(),
            'printing.printer' => ['nullable', 'string', 'max:255'],
            'printing.print_mode' => ['required', 'in:silent,dialog'],
            'printing.copies' => ['required', 'integer', 'min:1', 'max:5'],
            'printing.margins' => ['required', 'in:none,default,printableArea'],
            'printing.offset_x' => ['required', 'integer', 'between:-'.ReceiptPrintService::MAX_OFFSET_X.','.ReceiptPrintService::MAX_OFFSET_X],
            'printing.scale' => ['required', 'integer', 'between:'.ReceiptPrintService::MIN_SCALE.','.ReceiptPrintService::MAX_SCALE],
            'printing.auto_print' => ['required', 'in:ask,always,never'],
            'printing.cash_drawer' => ['required', 'in:'.implode(',', array_keys(CashDrawer::MODES))],
            'terminal.scale_barcode_prefixes' => ['nullable', 'string', 'max:100', 'regex:/^(\d{1,3}(,\d{1,3})*)?$/'],
            'terminal.scale_barcode_kind' => ['required', 'in:'.implode(',', array_keys(ScaleBarcode::KINDS))],
            'terminal.scale_barcode_item_digits' => ['required', 'integer', 'between:3,7'],
            'terminal.scale_barcode_decimals' => ['required', 'integer', 'between:0,3'],
            'terminal.order_retention_days' => ['required', 'integer', 'between:0,3650'],
            'terminal.idle_lock_minutes' => ['required', 'integer', 'between:0,240'],
            'terminal.touch_mode' => ['required', 'in:auto,on,off'],
            'terminal.theme' => ['required', 'in:light,dark,contrast'],
            'terminal.text' => ['required', 'in:normal,large'],
            // A restaurant's kitchen stations (KitchenStations), as their JSON list.
            'kitchen.stations' => ['required', 'json', 'max:20000'],
            'kitchen.fallback_to_receipt' => ['required', 'boolean'],
        ];
    }

    /**
     * Where each value of the file is stored (settings table parameter).
     *
     * @return array<string, string>
     */
    public static function parameters(): array
    {
        $parameters = [
            'receipt.layout' => 'receipt_layout',
            'receipt.paper_width' => 'receipt_paper_width',
            'receipt.font_size' => 'receipt_font_size',
            'receipt.header_text' => 'receipt_header_text',
            'receipt.footer_text' => 'receipt_footer_text',
            'printing.printer' => 'receipt_printer',
            'printing.print_mode' => 'receipt_print_mode',
            'printing.copies' => 'receipt_copies',
            'printing.margins' => 'receipt_margins',
            'printing.offset_x' => 'receipt_offset_x',
            'printing.scale' => 'receipt_scale',
            'printing.auto_print' => 'receipt_auto_print',
            'printing.cash_drawer' => CashDrawer::SETTING,
            'terminal.scale_barcode_prefixes' => ScaleBarcode::PREFIXES,
            'terminal.scale_barcode_kind' => ScaleBarcode::KIND,
            'terminal.scale_barcode_item_digits' => ScaleBarcode::ITEM_DIGITS,
            'terminal.scale_barcode_decimals' => ScaleBarcode::DECIMALS,
            'terminal.order_retention_days' => 'order_retention_days',
            'terminal.idle_lock_minutes' => 'idle_lock_minutes',
            'terminal.touch_mode' => 'touch_mode',
            'terminal.theme' => 'terminal_theme',
            'terminal.text' => 'terminal_text',
            'kitchen.stations' => KitchenStations::SETTING,
            'kitchen.fallback_to_receipt' => KitchenStations::FALLBACK,
        ];

        foreach (ReceiptPrintService::switchKeys() as $key) {
            $parameters["receipt.{$key}"] = 'receipt_'.$key;
        }

        return $parameters;
    }

    /**
     * This till's settings as the export file holds them.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $current = $this->currentValues();
        $profile = $this->profile->current();
        $data = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'app_version' => (string) AppVersion::current(),
            'exported_at' => now()->toIso8601String(),
            'exported_from' => ['outlet' => $profile['outlet']['name'] ?? null],
        ];

        foreach ($current as $path => $value) {
            data_set($data, $path, $value);
        }

        return $data;
    }

    public function exportJson(): string
    {
        return json_encode($this->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * What importing the file would change, without changing anything: refused with errors when the file is not a
     * settings file of a version this till knows or a value is not allowed.
     *
     * @param  array<int, string>  $installedPrinters
     * @return array{errors: list<string>, changes: array<string, array{from: mixed, to: mixed}>, notes: list<string>}
     */
    public function preview(string $json, array $installedPrinters): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            return ['errors' => ['This is not a Sylloo POS settings file.'], 'changes' => [], 'notes' => []];
        }

        if (! is_int($data['format_version'] ?? null) || $data['format_version'] > self::FORMAT_VERSION) {
            return ['errors' => ['The file is from a newer Sylloo POS. Update this terminal first.'], 'changes' => [], 'notes' => []];
        }

        $rules = self::rules();
        $values = [];
        $notes = [];

        foreach (['receipt', 'printing', 'terminal', 'kitchen'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $key => $value) {
                $path = "{$group}.{$key}";

                if (! array_key_exists($path, $rules)) {
                    $notes[] = "\"{$path}\" is not known on this version and was left out.";

                    continue;
                }

                $values[$path] = $value;
            }
        }

        $validator = Validator::make(self::undot($values), array_intersect_key($rules, $values));

        if ($validator->fails()) {
            return ['errors' => collect($validator->errors()->messages())->map(fn (array $messages, string $path) => "{$path}: {$messages[0]}")->values()->all(), 'changes' => [], 'notes' => $notes];
        }

        $printer = $values['printing.printer'] ?? null;

        if (filled($printer) && ! in_array($printer, $installedPrinters, true)) {
            unset($values['printing.printer']);
            $notes[] = "Printer {$printer} is not installed on this PC; the printer was kept as it is.";
        }

        $current = $this->currentValues();
        $changes = [];

        foreach ($values as $path => $value) {
            $value = self::normalise($path, $value);

            if ($current[$path] !== $value) {
                $changes[$path] = ['from' => $current[$path], 'to' => $value];
            }
        }

        return ['errors' => [], 'changes' => $changes, 'notes' => $notes];
    }

    /**
     * Save the changes of preview() in one go.
     *
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    public function apply(array $changes): void
    {
        $parameters = self::parameters();

        DB::transaction(function () use ($changes, $parameters) {
            foreach ($changes as $path => $change) {
                if (isset($parameters[$path])) {
                    Setting::put($parameters[$path], $change['to']);
                }
            }
        });
    }

    /**
     * Every value the file holds, as this till has it now.
     *
     * @return array<string, mixed>
     */
    protected function currentValues(): array
    {
        $settings = $this->receipts->settings();
        $retention = app(OrderRetention::class)->days();
        $values = [];

        foreach (array_keys(self::parameters()) as $path) {
            [$group, $key] = explode('.', $path, 2);

            $values[$path] = match (true) {
                $path === 'receipt.paper_width' => $settings['paper_width_setting'],
                $path === 'terminal.order_retention_days' => $retention,
                $path === 'terminal.idle_lock_minutes' => IdleMinutes::minutes(),
                $path === 'terminal.touch_mode' => Setting::valueOf('touch_mode') ?: 'auto',
                $path === 'terminal.theme' => Setting::valueOf('terminal_theme') ?: 'light',
                $path === 'terminal.text' => Setting::valueOf('terminal_text') ?: 'normal',
                $path === 'printing.cash_drawer' => CashDrawer::mode(),
                $path === 'terminal.scale_barcode_prefixes' => implode(',', ScaleBarcode::settings()['prefixes']),
                $path === 'terminal.scale_barcode_kind' => ScaleBarcode::settings()['kind'],
                $path === 'terminal.scale_barcode_item_digits' => ScaleBarcode::settings()['item_digits'],
                $path === 'terminal.scale_barcode_decimals' => ScaleBarcode::settings()['decimals'],
                $group === 'printing' && $key === 'printer' => $settings['printer'],
                $path === 'kitchen.stations' => json_encode(KitchenStations::all(), JSON_UNESCAPED_UNICODE),
                $path === 'kitchen.fallback_to_receipt' => KitchenStations::fallbackToReceipt(),
                default => $settings[$key] ?? null,
            };

            $values[$path] = self::normalise($path, $values[$path]);
        }

        return $values;
    }

    /**
     * A value as it is compared and stored: switches as booleans, numbers as integers, empty text as null.
     */
    protected static function normalise(string $path, mixed $value): mixed
    {
        $key = explode('.', $path, 2)[1];

        return match (true) {
            in_array($key, ReceiptPrintService::switchKeys(), true), $key === 'fallback_to_receipt' => (bool) $value,
            $key === 'stations' => json_encode(KitchenStations::clean((array) (is_string($value) ? json_decode($value, true) : $value)), JSON_UNESCAPED_UNICODE),
            in_array($key, ['copies', 'offset_x', 'scale', 'order_retention_days', 'idle_lock_minutes', 'scale_barcode_item_digits', 'scale_barcode_decimals'], true) => (int) $value,
            $key === 'paper_width' => (string) $value,
            default => $value === '' ? null : $value,
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function undot(array $values): array
    {
        $nested = [];

        foreach ($values as $path => $value) {
            data_set($nested, $path, $value);
        }

        return $nested;
    }
}
