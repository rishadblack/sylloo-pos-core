<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Rishadblack\SyllooPosCore\Models\Concerns\HasCachedImages;

/**
 * @property string|null $kind
 * @property string|null $name
 */
/**
 * @property string|null $kind
 * @property string|null $name
 */
class PaymentMethod extends Model
{
    use HasCachedImages, HasFactory, HasUlids, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    public static function cachedImages(): array
    {
        return ['logo_url' => 'logo_path'];
    }

    public function scopeActive($query)
    {
        return $query->where('payment_methods.status', 1);
    }

    /**
     * Whether it is cash: payments with it go into the cash drawer, count as cash in the X/Z report and refunds, and a
     * sale paid with it may be cash rounded. The ERP's own cash flag (`is_cash`) decides; until the method is pulled
     * again with the flag, its kind in the ERP or (from an ERP that sends no kind) a name with "cash" in it.
     */
    public function isCash(): bool
    {
        if ($this->is_cash !== null) {
            return (bool) $this->is_cash;
        }

        if (filled($this->kind)) {
            return $this->kind === 'cash';
        }

        return str_contains(mb_strtolower((string) $this->name), 'cash');
    }

    /**
     * Whether it is a card: its kind in the ERP (`card`), or (from an ERP that sends no kind) a name with "card" in it.
     * A card payment may keep the card's last four digits.
     */
    public function isCard(): bool
    {
        if (filled($this->kind)) {
            return $this->kind === 'card';
        }

        return str_contains(mb_strtolower((string) $this->name), 'card');
    }

    /**
     * Whether a sale paid with it may be cash rounded: the same rule as isCash(), so the rounding, the drawer, the
     * Z report and refunds agree about what cash is.
     */
    public function roundsCash(): bool
    {
        return $this->isCash();
    }

    /**
     * The ERP stores the method's fee as a plain percentage ("1.8"); return it in the
     * terminal's charge format ("1.8%"), or null when the method is free.
     */
    public function chargeRule(): ?string
    {
        $charge = rtrim(trim((string) $this->charge), '%');

        if (! is_numeric($charge) || (float) $charge <= 0) {
            return null;
        }

        return $charge.'%';
    }

    /**
     * The fee the customer pays on top of the given amount.
     */
    public function chargeAmountFor(float $amount): float
    {
        $chargeRule = $this->chargeRule();

        return $chargeRule ? round($amount * (float) rtrim($chargeRule, '%') / 100, 2) : 0.0;
    }
}
