<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The names of product variations from the ERP (pulled): the kinds (Colour, Size) and their values (Red, XL), which a
 * variant product names by id in `variant_items`. The variant picker shows them (VariantLabels).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->text('name')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('variant_template_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('variant_template_id')->nullable()->index();
            $table->text('name')->nullable();
            $table->bigInteger('sort')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_template_items');
        Schema::dropIfExists('variant_templates');
    }
};
