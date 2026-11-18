<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The restaurant tables this till serves, pulled from the ERP (`PosTable`, erp: docs/restaurant.md). LocalDataReset
 * empties it with every other table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 191);
            $table->string('area', 100)->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->index(['status', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dining_tables');
    }
};
