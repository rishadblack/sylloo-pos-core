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
        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->bigInteger('serial')->nullable();
            $table->text('name')->nullable();
            $table->string('code', 100)->nullable();
            $table->string('logo_url')->nullable();
            $table->decimal('charge', 20, 6)->nullable();
            $table->tinyInteger('type')->nullable();
            $table->tinyInteger('status')->default(1)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_methods');
    }
};
