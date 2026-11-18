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
        Schema::create('order_items', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->foreignUlid('order_id')->index();
            $table->foreignUlid('employee_id')->nullable();
            $table->foreignUlid('contact_id')->index();
            $table->foreignUlid('product_id')->nullable()->index();
            $table->json('name')->nullable();
            $table->timestamp('order_item_date')->nullable()->index();
            $table->decimal('amount', 20, 6)->default(0);
            $table->decimal('quantity', 20)->default(0);
            $table->string('vat')->nullable();
            $table->decimal('vat_amount', 20, 6)->default(0);
            $table->string('discount')->nullable();
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('subtotal', 20, 6)->default(0);
            $table->tinyInteger('is_return')->nullable();
            $table->tinyInteger('is_blank')->nullable();
            $table->timestamp('batch_tracking_updated_at')->nullable();
            $table->json('batch_tracking')->nullable();
            $table->timestamp('serial_tracking_updated_at')->nullable();
            $table->json('serial_tracking')->nullable();
            $table->json('additionals')->nullable();
            $table->tinyInteger('status')->default(1)->index();
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->foreignUlid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'order_item_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
