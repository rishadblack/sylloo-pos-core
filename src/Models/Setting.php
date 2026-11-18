<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Rishadblack\SyllooPosCore\Database\Factories\SettingFactory;

/**
 * A terminal setting: one row per parameter.
 * Values are stored as text; booleans as "1" / "0".
 */
class Setting extends Model
{
    use HasFactory, HasUlids;

    /**
     * The core's factory (the model is not in the app's namespace).
     */
    protected static function newFactory(): SettingFactory
    {
        return SettingFactory::new();
    }

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * General app settings, with their defaults.
     *
     * @var array<string, string|null>
     */
    public const GENERAL_DEFAULTS = [
        'timezone' => 'Asia/Dhaka',
        // Days a sale the ERP already has stays on the till (0 keeps every sale), see OrderRetention.
        'order_retention_days' => 90,
        // Folder of the daily database copies (empty: storage/app/backups), see LocalBackup.
        'backup_folder' => null,
        // Idle minutes before the terminal locks (0: never), see IdleLock.
        'idle_lock_minutes' => 10,
        // When the cash drawer on the receipt printer opens (off, cash, every), see CashDrawer.
        'cash_drawer' => 'off',
        // Barcodes of a shop scale (ScaleBarcode): prefixes (empty: off), weight or price, item digits, decimals.
        'scale_barcode_prefixes' => '',
        'scale_barcode_kind' => 'weight',
        'scale_barcode_item_digits' => 5,
        'scale_barcode_decimals' => 3,
        // Touch mode (audit Q12): auto (bigger buttons on a touch screen), on, off.
        'touch_mode' => 'auto',
        // The terminal's look (audit L3): light, dark or contrast; normal or large text.
        'terminal_theme' => 'light',
        'terminal_text' => 'normal',
        // The shop's own quick keys: product ids shown on the Quick keys tab (JSON list).
        'quick_keys' => '[]',
    ];

    /**
     * Store details earlier versions let the shop type in (the receipt header), with the demo values they shipped with.
     * The details come from the ERP now (TerminalProfile); typed ones are only printed until its first copy arrives,
     * never the demo values.
     *
     * @var array<string, string|null>
     */
    public const LEGACY_STORE_DEMO = [
        'name' => 'Sylloo Store',
        'sologan' => 'Smart retail, simplified',
        'address' => 'House 12, Road 5, Dhanmondi, Dhaka 1205',
        'mobile' => '+880 1700-000000',
        'email' => 'info@sylloo.com',
        'website_address' => 'www.sylloo.com',
        'logo' => 'images/sylloo-logo.png',
    ];

    /**
     * Receipt settings, with their defaults (ReceiptPrintService: the layout and what it shows, and the printer).
     * Every option has a safe default, so a new terminal prints a correct receipt without any setup.
     *
     * @var array<string, string|int|bool|null>
     */
    public const RECEIPT_DEFAULTS = [
        'receipt_printer' => null,
        // 'outlet' (the outlet's roll width from the ERP), 58 or 80
        'receipt_paper_width' => 'outlet',
        'receipt_print_mode' => 'silent',
        'receipt_copies' => 1,
        'receipt_margins' => 'none',
        'receipt_offset_x' => 0,
        'receipt_scale' => 100,
        'receipt_auto_print' => 'ask',
        'receipt_layout' => 'standard',
        'receipt_font_size' => 'normal',
        'receipt_show_logo' => true,
        'receipt_show_slogan' => true,
        'receipt_show_outlet_name' => true,
        'receipt_show_address' => true,
        'receipt_show_contact' => true,
        'receipt_show_email' => false,
        'receipt_show_website' => true,
        'receipt_show_vat_no' => true,
        'receipt_show_cashier' => true,
        'receipt_show_customer' => true,
        'receipt_show_note' => true,
        'receipt_show_barcode' => true,
        'receipt_show_code' => false,
        'receipt_show_unit' => true,
        'receipt_show_line_discount' => true,
        'receipt_show_line_vat' => false,
        'receipt_show_serials' => true,
        'receipt_show_warranty' => true,
        'receipt_show_amount_in_words' => true,
        'receipt_reprint_mark' => true,
        // A line above the receipt title, e.g. "Mushak 6.3"
        'receipt_header_text' => null,
        // One line per footer line (return policy, thank-you)
        'receipt_footer_text' => 'Thank you for shopping with us!',
        'receipt_barcode_type' => 'C128',
    ];

    /**
     * Default value of every setting (general and receipt), as stored. Inserted on first run by SettingSeeder and used
     * whenever a parameter has no row.
     *
     * @return array<string, string|null>
     */
    public static function defaults(): array
    {
        return array_map(fn ($value) => static::encode($value), [...self::GENERAL_DEFAULTS, ...self::RECEIPT_DEFAULTS]);
    }

    /**
     * The given parameters' values, saved values over defaults.
     *
     * @param  list<string>  $parameters
     * @return array<string, string|null>
     */
    public static function valuesOf(array $parameters): array
    {
        $defaults = array_intersect_key(static::defaults(), array_flip($parameters));

        return array_merge($defaults, static::whereIn('parameter', $parameters)->pluck('value', 'parameter')->all());
    }

    /**
     * One parameter's value, or the default when it has none.
     */
    public static function valueOf(string $parameter, ?string $default = null): ?string
    {
        $setting = static::where('parameter', $parameter)->first();

        if ($setting) {
            return $setting->value;
        }

        return array_key_exists($parameter, static::defaults()) ? static::defaults()[$parameter] : $default;
    }

    /**
     * Save a parameter, creating it when new.
     */
    public static function put(string $parameter, string|int|float|bool|null $value): void
    {
        static::updateOrCreate(['parameter' => $parameter], ['value' => static::encode($value)]);
    }

    /**
     * Text form of a value as stored in the value column.
     */
    public static function encode(string|int|float|bool|null $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
