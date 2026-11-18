<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The other units a product sells in, as the office sets them on its Units tab (erp: ProductPosData `units`): unit,
 * how many of the product's own unit one is (`factor`), its own sale price and whether it is the default to sell in.
 * Null from an older office: the unit list's conversions are used as before (Rishadblack\SyllooPosCore\Services\UnitConversion).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('units')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('units');
        });
    }
};
