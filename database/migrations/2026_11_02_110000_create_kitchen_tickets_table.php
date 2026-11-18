<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen tickets of a restaurant's till (erp: docs/restaurant.md, phase 5): what was sent to which kitchen printer, as
 * a snapshot, and whether it printed. A bill line remembers how much of it went to the kitchen and its note for the
 * cook. LocalDataReset empties the table with every other one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('order_id')->index();
            $table->unsignedInteger('number');
            $table->string('station', 100);
            $table->string('printer', 255)->nullable();
            $table->string('kind', 10)->default('new');
            $table->json('lines');
            $table->string('status', 10)->default('waiting');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->boolean('on_receipt_printer')->default(false);
            $table->ulid('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('tried_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'tried_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('kitchen_sent_quantity', 20, 6)->default(0);
            $table->string('kitchen_note', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['kitchen_sent_quantity', 'kitchen_note']);
        });
    }
};
