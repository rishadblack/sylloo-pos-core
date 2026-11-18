<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trust on the server (audit SEC-4, SEC-5):
 *
 * - each completed sale gets its number and hash in this till's chain of sales (Rishadblack\SyllooPosCore\Services\SaleChain), so the ERP
 *   notices a sale deleted or changed here before upload;
 * - each cashier keeps the largest discount their ERP roles allow (null: the ERP sent none, no limit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('chain_id', 26)->nullable();
            $table->unsignedBigInteger('chain_serial')->nullable();
            $table->string('chain_prev_hash', 64)->nullable();
            $table->string('chain_hash', 64)->nullable();
            $table->string('chain_signed_at', 40)->nullable();
            $table->index(['chain_id', 'chain_serial']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_discount_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['chain_id', 'chain_serial']);
            $table->dropColumn(['chain_id', 'chain_serial', 'chain_prev_hash', 'chain_hash', 'chain_signed_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('max_discount_percent');
        });
    }
};
