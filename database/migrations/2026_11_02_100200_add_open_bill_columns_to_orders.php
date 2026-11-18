<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An open bill is a draft order with a table (erp: docs/restaurant.md): which table and its name as it was, the guests,
 * who opened it and when. One open bill per table, by a partial unique index: a shop's rows have no table and never
 * meet it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('dining_table_id')->nullable();
            $table->string('table_name', 191)->nullable();
            $table->unsignedSmallInteger('guest_count')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->ulid('opened_by')->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX orders_one_open_bill_per_table ON orders (dining_table_id) WHERE status = 5 AND dining_table_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_one_open_bill_per_table');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['dining_table_id', 'table_name', 'guest_count', 'opened_at', 'opened_by']);
        });
    }
};
