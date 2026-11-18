<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale returns (audit Phase 3, SaleReturns): a return is an order of type 3 against a sale of this till
 * (`return_of_order_id`, its lines' `return_of_item_id`), or without a receipt with the manager who approved the
 * refund; the reason is kept. A refund kept as store credit is marked on its payment. An exchange is a sale paid with
 * the refund of its return (`exchange_return_id`), printed on one receipt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('return_of_order_id')->nullable()->index();
            $table->ulid('refund_approved_by')->nullable();
            $table->string('refund_reason', 191)->nullable();
            $table->ulid('exchange_return_id')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->ulid('return_of_item_id')->nullable()->index();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('store_credit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('store_credit');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['return_of_item_id']);
            $table->dropColumn('return_of_item_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['return_of_order_id']);
            $table->dropColumn(['return_of_order_id', 'refund_approved_by', 'refund_reason', 'exchange_return_id']);
        });
    }
};
