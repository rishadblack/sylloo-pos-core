<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Daily book category from the ERP (pulled): what cash put in (`flow_type` 1) or taken out (2) is for, so the ERP
 * books it to the right account. The account itself stays on the ERP.
 */
class DailyCategory extends Model
{
    use HasUlids;

    public const IN = 1;

    public const OUT = 2;

    protected $guarded = [];

    public function scopeUsable(Builder $query, int $flowType): void
    {
        $query->where('flow_type', $flowType)->where('status', 1)->whereNull('deleted_at')->orderByDesc('is_quick')->orderBy('sort_order');
    }

    /**
     * The name in the till's language (the ERP may send it translated, as JSON).
     */
    public function label(): string
    {
        $name = (string) $this->name;
        $names = json_decode($name, true);

        if (! is_array($names)) {
            return $name;
        }

        return (string) ($names[app()->getLocale()] ?? $names['en'] ?? reset($names) ?: '');
    }
}
