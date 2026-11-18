<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash rounding (ERP docs/cash-rounding.md): what a sale paid in cash was rounded by (`orders.rounding_amount`, signed,
 * part of `net_amount`) and whether the ERP counts a payment method as cash for it (`payment_methods.is_cash`, pulled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('rounding_amount', 20, 6)->default(0);
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->boolean('is_cash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('is_cash');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('rounding_amount');
        });
    }
};
