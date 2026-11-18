<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ERP's VAT rates and customer group prices, pulled so the till works out a sale exactly
     * like the server does (see Rishadblack\SyllooPosCore\Services\OrderCalculator).
     */
    public function up(): void
    {
        Schema::create('vats', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->tinyInteger('type')->default(1);
            $table->decimal('percentage', 20, 6)->default(0);
            $table->tinyInteger('status')->default(1)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_pricings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->index();
            $table->foreignUlid('contact_group_id')->nullable()->index();
            $table->decimal('sale_price', 20, 6)->nullable();
            $table->string('sale_vat', 20)->nullable();
            $table->decimal('sale_vat_amount', 20, 6)->nullable();
            $table->decimal('net_sale_price', 20, 6)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignUlid('sale_vat_id')->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sale_vat_id');
        });

        Schema::dropIfExists('product_pricings');
        Schema::dropIfExists('vats');
    }
};
