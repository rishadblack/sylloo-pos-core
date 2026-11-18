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
        Schema::create('transactions', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->bigInteger('serial')->nullable();
            $table->foreignUlid('payment_id')->nullable();
            $table->foreignUlid('payment_method_id')->nullable();
            $table->foreignUlid('employee_id')->nullable();
            $table->foreignUlid('contact_id')->nullable();
            $table->foreignUlid('order_id')->nullable();
            $table->foreignUlid('receipt_id')->nullable();
            $table->foreignUlid('desktop_pos_id')->index();
            $table->string('ref')->nullable();
            $table->timestamp('transaction_date')->nullable()->index();
            $table->string('code', 100)->nullable();
            $table->tinyInteger('flow')->index()->comment('1=in, 2=out');
            $table->decimal('amount', 20, 6)->default(0);
            $table->string('charge', 100)->nullable();
            $table->decimal('charge_amount', 20, 6)->default(0);
            $table->decimal('pay_change_amount', 20, 6)->default(0);
            $table->decimal('net_amount', 20, 6)->default(0);
            $table->text('note')->nullable();
            $table->tinyInteger('is_default')->nullable();
            $table->tinyInteger('is_opening')->nullable();
            $table->tinyInteger('status')->default(1)->index();
            $table->timestamp('last_sync_at')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->foreignUlid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
