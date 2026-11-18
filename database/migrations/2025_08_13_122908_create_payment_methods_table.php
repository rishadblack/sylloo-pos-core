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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->bigInteger('serial')->nullable();
            $table->text('name')->nullable();
            $table->string('code', 100)->nullable();
            $table->string('logo_url')->nullable();
            $table->decimal('minimum_payment', 20, 6)->nullable();
            $table->decimal('maximum_payment', 20, 6)->nullable();
            $table->string('charge', 20)->nullable();
            $table->tinyInteger('is_default')->nullable();
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
        Schema::dropIfExists('payment_methods');
    }
};
