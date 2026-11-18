<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The units the ERP sends with their conversion (a unit made of `factor` of its base unit) and the stock of each item
 * in the till's warehouse (on hand, held for confirmed orders, available), both pulled from the ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('units')) {
            Schema::create('units', function (Blueprint $table) {
                $table->ulid('id')->unique()->primary();
                $table->json('name')->nullable();
                $table->string('base_unit_id', 26)->nullable()->index();
                $table->decimal('factor', 20, 6)->default(1);
                $table->tinyInteger('status')->default(1)->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('stock_levels')) {
            Schema::create('stock_levels', function (Blueprint $table) {
                // The ERP sends one row per item, keyed by the item's id.
                $table->string('id', 26)->primary();
                $table->string('product_id', 26)->index();
                $table->string('warehouse_id', 26)->nullable();
                $table->decimal('on_hand', 20, 6)->default(0);
                $table->decimal('reserved', 20, 6)->default(0);
                $table->decimal('available', 20, 6)->default(0);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('units');
    }
};
