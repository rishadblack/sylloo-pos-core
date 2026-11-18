<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale of another till of the outlet, fetched from the office to make a return against it (SaleReturns): kept as a
 * read-only copy (`from_office`, never pushed or listed with this till's sales), with what was returned of each line
 * elsewhere (`order_items.returned_elsewhere`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('from_office')->default(false)->index();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('returned_elsewhere', 20, 6)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('returned_elsewhere');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['from_office']);
            $table->dropColumn('from_office');
        });
    }
};
