<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->tinyInteger('type')->nullable();
            $table->bigInteger('serial')->nullable();
            $table->foreignUlid('employee_id')->nullable();
            $table->foreignUlid('contact_id')->index();
            $table->foreignUlid('payment_method_id')->nullable();
            $table->foreignUlid('shipping_method_id')->nullable();
            $table->foreignUlid('coupon_id')->nullable();
            $table->foreignUlid('desktop_pos_id')->index();
            $table->string('ref')->nullable();
            $table->timestamp('order_date')->nullable()->index();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('delivery_date')->nullable();
            $table->string('code', 100)->nullable();
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->string('vat')->nullable();
            $table->decimal('vat_amount', 20, 6)->default(0);
            $table->string('discount')->nullable();
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('shipping_charge', 20, 6)->default(0);
            $table->decimal('additional_charge', 20, 6)->default(0);
            $table->string('additional_charge_details')->nullable();
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->decimal('paid_amount', 20, 6)->default(0);
            $table->decimal('due_amount', 20, 6)->default(0);
            $table->decimal('pay_change_amount', 20, 6)->default(0);
            $table->tinyInteger('is_partial_payment')->nullable();
            $table->tinyInteger('is_partial_delivery')->nullable();
            $table->string('currency_code')->nullable();
            $table->decimal('currency_rate', 15, 8)->default(0);
            $table->tinyInteger('invoice_type')->nullable();
            $table->tinyInteger('device_type')->nullable();
            $table->string('attach_file')->nullable();
            $table->text('stuff_note')->nullable();
            $table->text('note')->nullable();
            $table->tinyInteger('payment_status')->nullable();
            $table->tinyInteger('delivery_status')->nullable();
            $table->string('txn_details')->nullable();
            $table->tinyInteger('status')->default(1)->index();
            $table->foreignUlid('cancel_by')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignUlid('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('order_by')->nullable();
            $table->foreignUlid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('ready_for_sync_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('sync_response')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->foreignUlid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'order_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
