<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decisions 2026-11-10 (erp: docs/README.md §8q):
 *
 * - a customer's figures for the customer card and the credit limit check (Rishadblack\SyllooPosCore\Services\CustomerCredit): what they
 *   owe (`balance`), what is overdue and their credit limit, as the office said them at `balance_at` (empty from an
 *   older ERP: no limit is checked);
 * - dated discounts priced offline (Rishadblack\SyllooPosCore\Services\SalePricing): the price without the discount, and on a customer group
 *   price its discount with its dates and least quantity (a product already has those).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // `balance` and `credit_limit` are there since the first contacts table.
            $table->decimal('overdue', 20, 6)->nullable();
            $table->timestamp('balance_at')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('undiscounted_net_sale_price', 20, 6)->nullable();
            $table->decimal('undiscounted_sale_vat_amount', 20, 6)->nullable();
        });

        Schema::table('product_pricings', function (Blueprint $table) {
            $table->decimal('sale_discount_amount', 20, 6)->nullable();
            $table->timestamp('sale_discount_start')->nullable();
            $table->timestamp('sale_discount_end')->nullable();
            $table->decimal('sale_discount_min_qty', 20, 6)->nullable();
            $table->decimal('undiscounted_net_sale_price', 20, 6)->nullable();
            $table->decimal('undiscounted_sale_vat_amount', 20, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_pricings', function (Blueprint $table) {
            $table->dropColumn(['sale_discount_amount', 'sale_discount_start', 'sale_discount_end', 'sale_discount_min_qty', 'undiscounted_net_sale_price', 'undiscounted_sale_vat_amount']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['undiscounted_net_sale_price', 'undiscounted_sale_vat_amount']);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['overdue', 'balance_at']);
        });
    }
};
