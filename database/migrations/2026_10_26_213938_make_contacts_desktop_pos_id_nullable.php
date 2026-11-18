<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts made on the ERP (e.g. its Walk-in Customer) belong to no terminal, so `desktop_pos_id` may be empty;
 * a required column made the whole contacts pull fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->ulid('desktop_pos_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->ulid('desktop_pos_id')->nullable(false)->change();
        });
    }
};
