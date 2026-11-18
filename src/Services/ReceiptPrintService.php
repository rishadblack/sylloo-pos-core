<?php

namespace Rishadblack\SyllooPosCore\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Contracts\ReceiptPrinter;
use Rishadblack\SyllooPosCore\Models\Contact;
use Rishadblack\SyllooPosCore\Models\Order;
use Rishadblack\SyllooPosCore\Models\OrderItem;
use Rishadblack\SyllooPosCore\Models\PaymentMethod;
use Rishadblack\SyllooPosCore\Models\Setting;
use Rishadblack\SyllooPosCore\Models\Shift;
use Rishadblack\SyllooPosCore\Models\Transaction;
use Rishadblack\SyllooPosCore\Receipts\ReceiptOrderData;
use Rishadblack\SyllooPosCore\Support\AmountInWords;
use Rishadblack\SyllooPosCore\Support\PracticeMode;
use Throwable;

/**
 * Renders an order with one of the receipt layouts shipped with the app (resources/views/receipts/layouts) and sends
 * it to the receipt printer. The design is closed (audit §7.3): the admin picks a layout and switches what it shows on
 * the Settings page; the store details come from the ERP (TerminalProfile).
 */
class ReceiptPrintService
{
    /**
     * The receipt layouts, by setting value, with their names on the Settings page.
     *
     * @var array<string, string>
     */
    public const LAYOUTS = [
        'standard' => 'Standard: logo, items with price × quantity, totals',
        'compact' => 'Compact: no logo, one line per item (58 mm rolls, busy counters)',
        'detailed' => 'Detailed: item codes, units, VAT per line, serial numbers and warranty',
    ];

    /**
     * What the admin may switch on or off, by group, with the label on the Settings page (setting `receipt_<key>`).
     *
     * @var array<string, array<string, string>>
     */
    public const SWITCHES = [
        'Header' => [
            'show_logo' => 'Logo',
            'show_slogan' => 'Slogan',
            'show_outlet_name' => 'Outlet name',
            'show_address' => 'Address',
            'show_contact' => 'Mobile / phone',
            'show_email' => 'Email',
            'show_website' => 'Website',
            'show_vat_no' => 'VAT / BIN number',
        ],
        'Sale' => [
            'show_cashier' => 'Cashier',
            'show_customer' => 'Customer (never a walk-in customer)',
            'show_note' => 'Order note',
            'show_barcode' => 'Order barcode',
        ],
        'Items' => [
            'show_code' => 'Product code',
            'show_unit' => 'Unit',
            'show_line_discount' => 'Discount per item',
            'show_line_vat' => 'VAT per item',
            'show_serials' => 'Serial numbers',
            'show_warranty' => 'Warranty end',
        ],
        'Totals and footer' => [
            'show_amount_in_words' => 'Amount in words',
            'reprint_mark' => '"Reprint" on reprinted receipts',
        ],
    ];

    /**
     * Folder (on the public disk) that uploaded store logos were saved in by earlier versions.
     */
    public const LOGO_FOLDER = 'logos';

    /**
     * How far the receipt can be moved left (negative) or right (positive), in mm.
     */
    public const MAX_OFFSET_X = 20;

    /**
     * Smallest and largest receipt scale, in percent.
     */
    public const MIN_SCALE = 50;

    public const MAX_SCALE = 150;

    public function __construct(protected TerminalProfile $profile) {}

    /**
     * Receipt settings from the settings table (receipt_* parameters), typed, with defaults for missing ones.
     * `paper_width` is the width printed on (the outlet's when set to "outlet"); `paper_width_setting` the choice.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        // Falls back to the defaults if the settings can't be read, so a sale is never blocked by it.
        try {
            $saved = Setting::valuesOf(array_keys(Setting::RECEIPT_DEFAULTS));
        } catch (Throwable) {
            $saved = array_intersect_key(Setting::defaults(), Setting::RECEIPT_DEFAULTS);
        }

        $settings = [
            'printer' => $saved['receipt_printer'] ?: null,
            'paper_width_setting' => in_array((string) $saved['receipt_paper_width'], ['58', '80'], true) ? (string) $saved['receipt_paper_width'] : 'outlet',
            'print_mode' => (string) $saved['receipt_print_mode'],
            'copies' => max(1, (int) $saved['receipt_copies']),
            'margins' => (string) $saved['receipt_margins'],
            'offset_x' => (int) ($saved['receipt_offset_x'] ?? 0),
            'scale' => (int) ($saved['receipt_scale'] ?? 0) ?: 100,
            'auto_print' => (string) $saved['receipt_auto_print'],
            'layout' => array_key_exists((string) $saved['receipt_layout'], self::LAYOUTS) ? (string) $saved['receipt_layout'] : 'standard',
            'font_size' => $saved['receipt_font_size'] === 'large' ? 'large' : 'normal',
            'header_text' => $saved['receipt_header_text'] ?: null,
            'footer_text' => $saved['receipt_footer_text'] ?? null,
            'barcode_type' => $saved['receipt_barcode_type'] ?: 'C128',
        ];

        foreach (self::switchKeys() as $key) {
            $settings[$key] = (bool) $saved['receipt_'.$key];
        }

        return $this->withPaperWidth($settings);
    }

    /**
     * The width printed on: the chosen one, or the outlet's (80 mm when the ERP sent none).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function withPaperWidth(array $settings): array
    {
        $choice = (string) ($settings['paper_width_setting'] ?? 'outlet');
        $settings['paper_width'] = in_array($choice, ['58', '80'], true) ? (int) $choice : ($this->profile->receiptWidth() ?? 80);

        return $settings;
    }

    /**
     * @return list<string>
     */
    public static function switchKeys(): array
    {
        return array_keys(array_merge(...array_values(self::SWITCHES)));
    }

    /**
     * Save the given receipt settings (keys as returned by settings()); others are left as they are.
     *
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(array $settings): void
    {
        if (array_key_exists('paper_width_setting', $settings)) {
            $settings['paper_width'] = $settings['paper_width_setting'];
        }

        $editable = [...array_diff(array_keys($this->settings()), ['paper_width_setting']), 'paper_width'];

        foreach (Arr::only($settings, $editable) as $key => $value) {
            Setting::put('receipt_'.$key, $value);
        }
    }

    /**
     * Store details printed in the receipt header: the ERP's business and outlet details (TerminalProfile).
     *
     * @return array<string, ?string>
     */
    public function storeSettings(): array
    {
        try {
            return $this->profile->current() !== null ? $this->profile->storeDetails() : $this->typedStoreDetails();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Before the ERP's first copy: what the shop typed in an earlier version, never its demo values.
     *
     * @return array<string, ?string>
     */
    protected function typedStoreDetails(): array
    {
        $typed = Setting::query()
            ->whereIn('parameter', array_keys(Setting::LEGACY_STORE_DEMO))
            ->pluck('value', 'parameter')
            ->all();

        return collect(Setting::LEGACY_STORE_DEMO)
            ->map(fn (?string $demo, string $parameter) => filled($typed[$parameter] ?? null) && $typed[$parameter] !== $demo ? $typed[$parameter] : null)
            ->all();
    }

    /**
     * Receipt HTML for the order. Pass settings to preview unsaved changes; a reprint can say so on the receipt.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $store
     */
    public function render(Order $order, ?array $settings = null, ?array $store = null, bool $reprint = false): string
    {
        $settings ??= $this->settings();

        // Old dues paid without a sale print their own receipt (DueCollections).
        $html = DueCollections::is($order)
            ? view('sylloo-pos-core::receipts.due-collection', $this->dueCollectionData($order, $settings, $store ?? $this->storeSettings(), $reprint))->render()
            : view('sylloo-pos-core::receipts.layouts.'.$settings['layout'], $this->templateData($order, $settings, $store ?? $this->storeSettings(), $reprint))->render();

        if (PracticeMode::isOn()) {
            $html = PracticeMode::markReceipt($html);
        }

        return $this->withContentSecurityPolicy($this->withPositionAdjustment($html, $settings));
    }

    /**
     * What a receipt page may load: no scripts, no network requests, no forms. A receipt only needs its own styles and
     * images (the logo and barcode are data: URLs).
     */
    public const CONTENT_SECURITY_POLICY = "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; script-src 'none'; form-action 'none'; base-uri 'none'";

    public function withContentSecurityPolicy(string $html): string
    {
        $meta = '<meta http-equiv="Content-Security-Policy" content="'.e(self::CONTENT_SECURITY_POLICY).'">';

        if (preg_match('/<head[^>]*>/i', $html)) {
            return preg_replace('/<head[^>]*>/i', '$0'.$meta, $html, 1);
        }

        return $meta.$html;
    }

    /**
     * Apply the horizontal offset and scale from the settings, so a printer that prints off-centre or cuts the edges
     * can be lined up from Settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function withPositionAdjustment(string $html, array $settings): string
    {
        $offsetX = max(-self::MAX_OFFSET_X, min(self::MAX_OFFSET_X, (int) ($settings['offset_x'] ?? 0)));
        $scale = max(self::MIN_SCALE, min(self::MAX_SCALE, (int) ($settings['scale'] ?? 100)));

        if ($offsetX === 0 && $scale === 100) {
            return $html;
        }

        $style = '<style id="receipt-position">'
            .'html { position: relative; left: '.$offsetX.'mm; }'
            .'body { zoom: '.($scale / 100).'; }'
            .'</style>';

        if (preg_match('/<\/head>/i', $html)) {
            return preg_replace('/<\/head>/i', $style.'</head>', $html, 1);
        }

        return $style.$html;
    }

    /**
     * The layout's variables: the order, the shop and the options.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $store
     * @return array<string, mixed>
     */
    public function templateData(Order $order, array $settings, array $store, bool $reprint = false): array
    {
        $order->loadMissing(['contact', 'creator', 'paymentMethod', 'orderItem.product', 'orderItem.unit', 'transaction.paymentMethod', 'returnOf', 'exchangeReturn.orderItem']);

        $receiptOrder = ReceiptOrderData::make($order)->barcodeType($settings['barcode_type'] ?? 'C128')->resolve();

        return [
            'order' => $receiptOrder,
            'shop' => $this->shop($store),
            'options' => $settings,
            'paperWidth' => (int) ($settings['paper_width'] ?? 80),
            'reprint' => $reprint && ($settings['reprint_mark'] ?? true),
            'amountInWords' => ($settings['show_amount_in_words'] ?? true) ? AmountInWords::format($order->net_amount) : '',
            'footerLines' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($settings['footer_text'] ?? ''))))),
        ];
    }

    /**
     * The due collection receipt's variables: the collection with its payments, what the customer owes now (as this
     * till knows it, CustomerCredit), the shop and the options.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $store
     * @return array<string, mixed>
     */
    public function dueCollectionData(Order $order, array $settings, array $store, bool $reprint = false): array
    {
        $order->loadMissing(['contact', 'creator', 'transaction.paymentMethod']);

        return [
            'order' => $order,
            'shop' => $this->shop($store),
            'options' => $settings,
            'paperWidth' => (int) ($settings['paper_width'] ?? 80),
            'reprint' => $reprint && ($settings['reprint_mark'] ?? true),
            'owedNow' => $order->contact ? app(CustomerCredit::class)->owed($order->contact) : null,
            'footerLines' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($settings['footer_text'] ?? ''))))),
        ];
    }

    /**
     * Print the order's receipt. Never throws: the sale is already saved, so a printer
     * problem must not break checkout. Returns whether the receipt was sent.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  array<string, mixed>|null  $store
     */
    public function print(Order $order, ?array $settings = null, ?array $store = null, bool $reprint = false): bool
    {
        $settings ??= $this->settings();

        try {
            $html = $this->render($order, $settings, $store, $reprint);

            $this->printer()->print($html, $this->printerName($settings['printer']), $settings);

            return true;
        } catch (Throwable $exception) {
            Log::error('Receipt print failed: '.$exception->getMessage(), ['order_id' => $order->id]);

            return false;
        }
    }

    /**
     * Another printout made on this till (a kitchen ticket) made ready like a receipt: the practice mark, the content
     * security policy and the offset and scale of the receipt settings.
     */
    public function finishHtml(string $html): string
    {
        $settings = $this->settings();

        if (PracticeMode::isOn()) {
            $html = PracticeMode::markReceipt($html);
        }

        return $this->withContentSecurityPolicy($this->withPositionAdjustment($html, $settings));
    }

    /**
     * Send a finished printout to a printer (null: the receipt printer), always silently, never with the print dialog.
     * Throws when it can not be handed to the printer; the caller keeps the work and says so.
     */
    public function sendHtml(string $html, ?string $printerName, int $copies = 1): void
    {
        $settings = $this->settings();

        $this->printer()->print($html, $this->printerName($printerName ?: $settings['printer']), [...$settings, 'print_mode' => 'silent', 'copies' => $copies]);
    }

    /**
     * Whether a named printer is installed: true when it is, or the printers can not be listed (as printerProblem()).
     */
    public function printerInstalled(string $printer): bool
    {
        $printers = Cache::remember('pos.printer_check', self::PRINTER_CHECK_SECONDS, fn () => $this->printers());

        return $printers === [] || array_key_exists($printer, $printers);
    }

    /**
     * The X report (the shift so far) or, once closed, the Z report (ShiftRegister).
     */
    public function renderShiftReport(Shift $shift, bool $final): string
    {
        $settings = $this->settings();

        $html = view('sylloo-pos-core::receipts.shift-report', [
            'shift' => $shift,
            'final' => $final,
            'summary' => app(ShiftRegister::class)->summary($shift),
            'movements' => $shift->cashMovements()->oldest()->get(),
            'cashier' => (string) ($shift->user?->name ?? ''),
            'shop' => $this->storeSettings(),
            'paperWidth' => (int) ($settings['paper_width'] ?? 80),
        ])->render();

        if (PracticeMode::isOn()) {
            $html = PracticeMode::markReceipt($html);
        }

        return $this->withContentSecurityPolicy($this->withPositionAdjustment($html, $settings));
    }

    /**
     * Print the X or Z report on the receipt printer. Never throws; returns whether it was sent.
     */
    public function printShiftReport(Shift $shift, bool $final): bool
    {
        $settings = $this->settings();

        try {
            $this->printer()->print($this->renderShiftReport($shift, $final), $this->printerName($settings['printer']), [...$settings, 'copies' => 1]);

            return true;
        } catch (Throwable $exception) {
            Log::error('Shift report print failed: '.$exception->getMessage(), ['shift_id' => $shift->id]);

            return false;
        }
    }

    /**
     * Installed printers, as name => display name. Empty when they can't be listed.
     *
     * @return array<string, string>
     */
    public function printers(): array
    {
        try {
            return $this->printer()->printers();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Seconds the installed printers are remembered for the printer check (the tray asks often).
     */
    public const PRINTER_CHECK_SECONDS = 60;

    /**
     * Why the receipt may not print, said before the first sale rather than after it (audit OP-8): the chosen
     * printer is not installed or not connected. Null when it is fine, the system default is used, or the printers
     * can not be listed.
     */
    public function printerProblem(): ?string
    {
        $printer = $this->settings()['printer'];

        if (! $printer) {
            return null;
        }

        $printers = Cache::remember('pos.printer_check', self::PRINTER_CHECK_SECONDS, fn () => $this->printers());

        if ($printers === [] || array_key_exists($printer, $printers)) {
            return null;
        }

        return "Receipt printer {$printer} is not connected";
    }

    /**
     * An unsaved example order for previewing and test printing the receipt.
     */
    public function sampleOrder(): Order
    {
        $order = new Order([
            'code' => 'POS1A2B3C4D',
            'order_date' => now(),
            'subtotal' => 450,
            'vat_amount' => 22.5,
            'discount' => '20',
            'discount_amount' => 20,
            'net_amount' => 452.5,
            'paid_amount' => 500,
            'due_amount' => 0,
            'pay_change_amount' => 47.5,
            'note' => 'Sample receipt',
        ]);

        $cash = new PaymentMethod(['name' => 'Cash']);

        $order->setRelation('contact', new Contact(['name' => 'Walk-in Customer', 'is_walk_in' => 1]));
        $order->setRelation('creator', Auth::user());
        $order->setRelation('paymentMethod', $cash);
        $order->setRelation('orderItem', collect([
            ['name' => 'Premium Green Tea', 'amount' => 120, 'quantity' => 2, 'subtotal' => 240],
            ['name' => 'Classic Black Coffee 200g', 'amount' => 150, 'quantity' => 1, 'subtotal' => 150],
            ['name' => 'Butter Cookies', 'amount' => 60, 'quantity' => 1, 'subtotal' => 60],
        ])->map(fn (array $item) => (new OrderItem($item))->setRelation('product', null)));
        $order->setRelation('transaction', collect([
            (new Transaction(['amount' => 500, 'pay_change_amount' => 47.5, 'net_amount' => 452.5, 'flow' => 1]))->setRelation('paymentMethod', $cash),
        ]));

        return $order;
    }

    /**
     * The shop's details with the logo ready to print.
     *
     * @param  array<string, mixed>  $shop
     * @return array<string, mixed>
     */
    protected function shop(array $shop): array
    {
        $shop['logo'] = $this->printableLogo($shop['logo'] ?? null);

        return $shop;
    }

    /**
     * A logo URL is used as is. A file in public/ or storage/ is inlined, because the receipt is printed from an HTML
     * string with no base URL.
     */
    public function printableLogo(?string $logo): ?string
    {
        if (! $logo) {
            return null;
        }

        if (Str::startsWith($logo, ['http://', 'https://', 'data:'])) {
            return $logo;
        }

        $path = collect([public_path($logo), storage_path('app/public/'.$logo)])->first(fn (string $path) => File::isFile($path));

        return $path ? 'data:'.File::mimeType($path).';base64,'.base64_encode(File::get($path)) : null;
    }

    /**
     * The printer the till answers with (the PC till prints the page with Electron).
     */
    protected function printer(): ReceiptPrinter
    {
        return app(ReceiptPrinter::class);
    }

    /**
     * A chosen printer by name; no name is the system's default printer.
     */
    protected function printerName(?string $name): ?string
    {
        return $name ? $name : null;
    }
}
