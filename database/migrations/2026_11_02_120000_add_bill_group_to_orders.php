<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A table's bill split by items (erp: docs/restaurant.md, phase 6): every share is an ordinary sale, and all shares of
 * one bill carry the same group id. Empty on every other sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('bill_group_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['bill_group_id']);
            $table->dropColumn('bill_group_id');
        });
    }
};
