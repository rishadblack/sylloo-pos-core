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
        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->bigInteger('serial')->nullable();
            $table->foreignUlid('unit_id')->nullable();
            $table->json('unit_name')->nullable();
            $table->foreignUlid('brand_id')->nullable();
            $table->json('category_ids')->nullable();
            $table->foreignUlid('parent_id')->nullable();
            $table->foreignUlid('product_type_id')->nullable();
            $table->string('code', 100);
            $table->json('name')->nullable();
            $table->json('short_description')->nullable();
            $table->json('description')->nullable();
            $table->string('image_url')->nullable();
            $table->tinyInteger('is_has_variation')->nullable();
            $table->bigInteger('sort')->nullable();
            $table->decimal('ltr', 20, 2)->default(0);
            $table->decimal('ctn', 20)->default('0');
            $table->tinyInteger('status')->default(1)->index();
            $table->tinyInteger('is_top_rated')->nullable();
            $table->tinyInteger('is_top_sales')->nullable();
            $table->tinyInteger('is_featured')->nullable();
            $table->tinyInteger('is_returnable')->nullable();
            $table->tinyInteger('is_warrantable')->nullable();
            $table->tinyInteger('is_serialize')->nullable();
            $table->tinyInteger('is_batch')->nullable();
            $table->decimal('warranty_value', 20, 2)->nullable();
            $table->tinyInteger('warranty_type')->nullable();
            $table->decimal('purchase_price', 20, 6)->default(0);
            $table->string('purchase_vat', 20)->nullable();
            $table->decimal('purchase_vat_amount', 20, 6)->default(0);
            $table->decimal('net_purchase_price', 20, 6)->default(0);
            $table->decimal('sale_price', 20, 6)->default(0);
            $table->string('profit_margin', 20)->default(0);
            $table->string('sale_vat', 20)->nullable();
            $table->decimal('sale_vat_amount', 20, 6)->default(0);
            $table->string('sale_discount', 20)->nullable();
            $table->decimal('sale_discount_amount', 20, 6)->default(0);
            $table->timestamp('sale_discount_start')->nullable();
            $table->timestamp('sale_discount_end')->nullable();
            $table->decimal('sale_discount_min_qty', 20, 2)->nullable();
            $table->decimal('net_sale_price', 20, 6)->default(0);

            // Inventory Start
            $table->tinyInteger('is_stock_available')->nullable();
            $table->tinyInteger('is_inventory_disable')->nullable();
            $table->tinyInteger('is_any_outlet')->nullable();
            $table->decimal('low_stock_alert', 20, 2)->nullable();
            $table->tinyInteger('is_out_of_stock_sale')->nullable();
            $table->json('variants')->nullable();
            $table->json('variant_items')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
