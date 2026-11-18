<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Services\ProductSearchIndex;

/**
 * Fast product search and scans (audit M4): one lower-cased `products.search_text` (the name in every language, the
 * code and the barcodes) searched with a single LIKE, and `product_barcodes` (one row per barcode) for an exact scan
 * by index. Both are filled when products are pulled (ProductSearchIndex); the products already on the till now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('search_text')->nullable();
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->string('code', 191)->primary();
            $table->ulid('product_id')->index();
        });

        app(ProductSearchIndex::class)->rebuild();
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('search_text');
        });
    }
};
