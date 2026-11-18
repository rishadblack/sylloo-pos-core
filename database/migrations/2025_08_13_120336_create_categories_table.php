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
        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->bigInteger('serial')->nullable();
            $table->foreignUlid('parent_id')->nullable(); // null
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->bigInteger('sort')->nullable();
            $table->string('image_url', 100)->nullable();
            $table->string('image_icon', 100)->nullable();
            $table->string('icon', 100)->nullable();
            $table->tinyInteger('is_featured')->nullable();
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
        Schema::dropIfExists('categories');
    }
};
