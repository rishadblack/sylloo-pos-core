<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rishadblack\SyllooPosCore\Services\SyncErrorLog;

class Order extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'order_date' => 'datetime',
        'from_office' => 'boolean',
        'due_date' => 'datetime',
        'delivery_date' => 'datetime',
        'is_paid' => 'datetime',
        'is_approved' => 'datetime',
        'is_checked' => 'datetime',
        'is_canceled' => 'datetime',
        'last_sync_at' => 'datetime',
        'sync_response' => 'array',
        'sync_issues' => 'array',
        'sync_warnings' => 'array',
        'sync_retry_at' => 'datetime',
        'opened_at' => 'datetime',
    ];

    /**
     * Number each order on this terminal as it is created. The ERP keeps its own serials,
     * so this local number is never pushed (see PushErpJob).
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->serial ??= static::nextSerial($order->type);
        });
    }

    /**
     * Next local serial for orders of a type (sale, purchase, ...).
     */
    public static function nextSerial(?int $type): int
    {
        return (int) static::withTrashed()->where('type', $type)->max('serial') + 1;
    }

    public static function setAdditionalConditionOnSerial($query, $model)
    {
        return $query->where('orders.type', $model->getAttribute('type'));
    }

    public function scopeActive($query)
    {
        return $query->where('orders.status', 1);
    }

    public function scopeSale($query)
    {
        return $query->where('orders.type', 1)->where('orders.invoice_type', 1);
    }

    public function scopeLastThirtyDays($query)
    {
        return $query->whereDate('orders.order_date', '>=', now()->subDays(30));
    }

    public function scopeEcommerce($query)
    {
        return $query->where('orders.invoice_type', 3);
    }

    public function scopePurchase($query)
    {
        return $query->where('orders.type', 2);
    }

    public function scopeSaleReturn($query)
    {
        return $query->where('orders.type', 3);
    }

    public function scopePurchaseReturn($query)
    {
        return $query->where('orders.type', 4);
    }

    public function scopeDue($query)
    {
        return $query->where('orders.due_amount', '>', 0);
    }

    public function scopeSalesDue($query)
    {
        return $query->whereRaw('(total_amount - paid_amount) > 0');
    }

    /**
     * Orders stuck in "uploading" longer than this are treated as interrupted.
     */
    public const SYNC_STALE_AFTER_MINUTES = 10;

    /**
     * A held (draft) order: saved on this terminal only, never uploaded until completed.
     */
    public const STATUS_DRAFT = 5;

    public function scopeDraft($query)
    {
        return $query->where('orders.status', self::STATUS_DRAFT);
    }

    /**
     * Drafts the cashier held (the F7 list), not the open bills of a restaurant's tables.
     */
    public function scopeHeldOnly($query)
    {
        return $query->draft()->whereNull('orders.dining_table_id');
    }

    /**
     * The open bills of a restaurant's tables: drafts with a table, on this till only until paid.
     */
    public function scopeTableBill($query)
    {
        return $query->draft()->whereNotNull('orders.dining_table_id');
    }

    public function scopeNotDraft($query)
    {
        return $query->where('orders.status', '!=', self::STATUS_DRAFT);
    }

    public function isDraft(): bool
    {
        return (int) $this->status === self::STATUS_DRAFT;
    }

    public function scopeSyncPending($query)
    {
        return $query->notDraft()
            ->whereNull('orders.last_sync_at')
            ->whereNull('orders.ready_for_sync_at')
            ->whereNull('orders.sync_response');
    }

    public function scopeSyncUploading($query)
    {
        return $query->whereNull('orders.last_sync_at')
            ->where('orders.ready_for_sync_at', '>=', now()->subMinutes(self::SYNC_STALE_AFTER_MINUTES));
    }

    public function scopeSyncFailed($query)
    {
        return $query->whereNull('orders.last_sync_at')
            ->where(function ($query) {
                $query->where(fn ($query) => $query->whereNull('orders.ready_for_sync_at')->whereNotNull('orders.sync_response'))
                    ->orWhere('orders.ready_for_sync_at', '<', now()->subMinutes(self::SYNC_STALE_AFTER_MINUTES));
            });
    }

    /**
     * Current ERP sync state of the order: synced, uploading, failed or pending.
     */
    public function syncState(): string
    {
        if ($this->last_sync_at) {
            return 'synced';
        }

        if ($this->ready_for_sync_at) {
            return Carbon::parse($this->ready_for_sync_at)->gte(now()->subMinutes(self::SYNC_STALE_AFTER_MINUTES))
                ? 'uploading'
                : 'failed';
        }

        return $this->sync_response ? 'failed' : 'pending';
    }

    /**
     * Human readable reason the ERP rejected the order, if any.
     */
    public function syncErrorMessage(): string
    {
        if ($this->ready_for_sync_at && ! $this->sync_response) {
            return 'Upload interrupted';
        }

        $response = $this->sync_response;

        if ($response === null || $response === '' || $response === []) {
            return 'Rejected by ERP';
        }

        return SyncErrorLog::rejectionMessage($response);
    }

    /**
     * Machine codes of a refusal the ERP answers (`failed_codes`).
     */
    public const SYNC_CODE_SERIAL_REQUIRED = 'serial_required';

    public const SYNC_CODE_SERVER_ERROR = 'server_error';

    /**
     * The ERP kept the sale but found something the office has to check.
     */
    public const SYNC_STATUS_WITH_ISSUES = 'received_with_issues';

    /**
     * Sent and kept by the ERP, but with problems the office has to check ("Sent – needs checking at the office").
     */
    public function needsChecking(): bool
    {
        return $this->last_sync_at !== null && ($this->sync_status === self::SYNC_STATUS_WITH_ISSUES || ! empty($this->sync_issues));
    }

    /**
     * The ERP refused the sale because a serial-numbered line has not one serial number per unit.
     */
    public function needsSerialFix(): bool
    {
        return $this->last_sync_at === null && $this->sync_code === self::SYNC_CODE_SERIAL_REQUIRED;
    }

    /**
     * The issues the ERP found on a kept sale, each in plain words.
     *
     * @return list<string>
     */
    public function syncIssueTexts(): array
    {
        return collect((array) $this->sync_issues)
            ->filter(fn ($issue) => is_array($issue))
            ->map(fn (array $issue) => self::issueText($issue))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One issue the ERP found on a sale, in plain words; a code this terminal does not know shows the ERP's message.
     *
     * @param  array{code?: string, message?: string, serial?: ?string, product_id?: ?string}  $issue
     */
    public static function issueText(array $issue): string
    {
        $code = (string) ($issue['code'] ?? '');
        $serial = filled($issue['serial'] ?? null) ? 'Serial '.$issue['serial'] : 'A serial number';
        $message = trim((string) ($issue['message'] ?? ''));

        $text = match (true) {
            $code === 'serial_sold' => "{$serial} was already sold.",
            $code === 'serial_elsewhere' => "{$serial} is in stock at another shop.",
            $code === 'serial_unavailable' => "{$serial} is not in stock (returned or damaged).",
            $code === 'serial_unknown' => "{$serial} is not known to the office.",
            $code === 'serial_other_product' => "{$serial} belongs to another product.",
            $code === 'serial_duplicate' => "{$serial} is on the sale twice.",
            str_starts_with($code, 'batch_') => 'The batch of an item could not be used; the office picks the batch.',
            $code === 'product_deleted' => 'An item was removed from the product list at the office.',
            $code === 'product_unknown' => 'An item is not known to the office.',
            $code === 'customer_unknown' => 'The customer is not known to the office; the sale was booked to the walk-in customer.',
            $code === 'customer_group_missing' => 'The customer group was not found at the office.',
            $code === 'payment_method_unknown' => 'A payment method is not known to the office.',
            $code === 'stock_short' => 'The office did not have enough stock of an item for this sale.',
            $code === 'total_changed' => 'The office worked out a different total for this sale.',
            $code === 'order_id_taken' => 'The office saved this sale under a new number.',
            $code === 'period_locked' => 'The sale date is in a closed accounting period; the office will post it.',
            $code === 'order_type_missing' => 'The POS sale type is not set up at the office.',
            default => null,
        };

        return $text ?? ($message !== '' ? $message : 'The office has to check this sale.');
    }

    /**
     * What the office changed on a kept sale without needing a check (it is never parked for these), in plain words.
     *
     * @return list<string>
     */
    public function syncWarningTexts(): array
    {
        return collect((array) $this->sync_warnings)
            ->filter(fn ($warning) => is_array($warning))
            ->map(fn (array $warning) => self::warningText($warning))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One warning the ERP answered for a kept sale, in plain words, or null for one the cashier does not need.
     *
     * @param  array{code?: string, product_id?: ?string, unit_id?: ?string, replaced_by?: ?string}  $warning
     */
    public static function warningText(array $warning): ?string
    {
        $productName = filled($warning['product_id'] ?? null) ? Product::withTrashed()->find($warning['product_id'])?->name : null;
        $item = filled($productName) ? (string) $productName : 'An item';

        return match ((string) ($warning['code'] ?? '')) {
            'unit_replaced' => self::unitReplacedText($item, $warning),
            'warehouse_replaced' => "{$item} was taken from another warehouse of the shop at the office.",
            default => null,
        };
    }

    /**
     * The office booked a line in the item's own unit instead of the unit the till sold it in.
     *
     * @param  array{unit_id?: ?string, replaced_by?: ?string}  $warning
     */
    protected static function unitReplacedText(string $item, array $warning): string
    {
        $sold = filled($warning['unit_id'] ?? null) ? Unit::withTrashed()->find($warning['unit_id'])?->name : null;
        $used = filled($warning['replaced_by'] ?? null) ? Unit::withTrashed()->find($warning['replaced_by'])?->name : null;

        $text = filled($sold) ? "{$item} was sold in {$sold}, but the office booked it" : "{$item} was booked by the office";

        return $text.(filled($used) ? " in {$used}." : ' in its own unit.');
    }

    /**
     * What the cashier sees about a failed upload: the plain reason and what to do.
     */
    public function syncFailureHint(): ?string
    {
        return match ($this->sync_code) {
            self::SYNC_CODE_SERIAL_REQUIRED => 'Add one serial number for each unit, then press "Save and resend".',
            self::SYNC_CODE_SERVER_ERROR => $this->sync_retry_at ? 'The office server had a problem. The sale is sent again at '.$this->sync_retry_at->format('h:i A').'.' : 'The office server had a problem. The sale is sent again shortly.',
            default => null,
        };
    }

    public function orderItem(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The sale a return was made against (SaleReturns).
     */
    public function returnOf(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'return_of_order_id');
    }

    /**
     * The return whose refund paid this sale (an exchange).
     */
    public function exchangeReturn(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'exchange_return_id');
    }

    public function isReturn(): bool
    {
        return (int) $this->type === 3;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The cashier who rang up the order on this terminal.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function shippingtMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function transaction(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function lastTransaction(): HasOne
    {
        return $this->hasOne(Transaction::class)->latest('transaction_date');
    }

    public function generateCode()
    {
        do {
            $code = $this->generateOfflineOrderCode();
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /**
     * The terminal's number in the ERP (sent at activation, sign-in and in each heartbeat), stored as a till setting.
     */
    public const TERMINAL_SERIAL_SETTING = 'terminal_serial';

    /**
     * "POS" + the terminal's number + 8 random characters (e.g. POS12-1A2B3C4D), so two terminals of one business
     * never make the same code. A terminal that does not know its number yet uses POS + 8 characters.
     */
    protected function generateOfflineOrderCode()
    {
        $random = strtoupper(substr(Str::uuid()->toString(), 0, 8));
        $terminalSerial = rescue(fn () => Setting::valueOf(self::TERMINAL_SERIAL_SETTING), null, false);

        return is_numeric($terminalSerial) ? 'POS'.(int) $terminalSerial.'-'.$random : 'POS'.$random;
    }

    /**
     * Remember the terminal's number the ERP sent (activation, sign-in, heartbeat).
     */
    public static function rememberTerminalSerial(mixed $serial): void
    {
        if (is_numeric($serial) && (int) $serial > 0) {
            Setting::put(self::TERMINAL_SERIAL_SETTING, (int) $serial);
        }
    }
}
