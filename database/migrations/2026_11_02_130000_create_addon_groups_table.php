<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restaurant's add-ons and notes (erp: docs/restaurant.md, phase 7): the add-on groups pulled from the ERP, one row per
 * group with its choices and what it applies to; an add-on line names its dish; a product sold only as an add-on stays
 * off the product tiles. LocalDataReset empties the table with every other one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addon_groups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 191);
            $table->unsignedTinyInteger('min_choices')->default(0);
            $table->unsignedTinyInteger('max_choices')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->json('items')->nullable();
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->ulid('parent_item_id')->nullable()->index();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->tinyInteger('is_addon_only')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_groups');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['parent_item_id']);
            $table->dropColumn('parent_item_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_addon_only');
        });
    }
};
