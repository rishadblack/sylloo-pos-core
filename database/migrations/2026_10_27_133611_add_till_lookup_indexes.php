<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for what the till looks up on every scan and every sale: a product by its code, a customer by mobile,
 * an order by its code, and the orders still to upload (or old enough to remove).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('code');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->index('mobile');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('code');
            $table->index(['last_sync_at', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['code']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['mobile']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['code']);
            $table->dropIndex(['last_sync_at', 'status']);
            $table->dropIndex(['created_at']);
        });
    }
};
