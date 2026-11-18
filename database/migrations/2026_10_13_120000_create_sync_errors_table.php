<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sync problems seen on this terminal, reported to the ERP with the next heartbeat.
     * Only error texts are kept: never order contents, amounts or customer details.
     */
    public function up(): void
    {
        Schema::create('sync_errors', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('occurred_at')->index();
            $table->string('type', 20);
            $table->string('code', 60)->nullable();
            $table->string('order_id', 26)->nullable();
            $table->string('message', 500);
            $table->timestamp('reported_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_errors');
    }
};
