<?php

namespace Rishadblack\SyllooPosCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A restaurant table this till serves, pulled from the ERP (erp: PosTable). Nothing is typed in here. An open bill keeps
 * the table's name, so a table removed in the office stays open until it is paid.
 */
class DiningTable extends Model
{
    use HasUlids;

    protected $guarded = [];

    public function scopeUsable(Builder $query): void
    {
        $query->where('dining_tables.status', 1)->orderBy('dining_tables.sort')->orderBy('dining_tables.name');
    }
}
