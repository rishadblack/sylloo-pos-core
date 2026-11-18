<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kits (a product made of parts, `kit_components` [{product_id, quantity}]), the unit a line was sold in (sent to the
 * ERP as `OrderItem.*.unit_id`) and the warnings the ERP answered for a kept sale (e.g. `unit_replaced`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->tinyInteger('is_kit')->nullable()->after('is_batch');
            $table->json('kit_components')->nullable()->after('is_kit');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('unit_id', 26)->nullable()->after('product_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('sync_warnings')->nullable()->after('sync_issues');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('sync_warnings');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('unit_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_kit', 'kit_components']);
        });
    }
};
