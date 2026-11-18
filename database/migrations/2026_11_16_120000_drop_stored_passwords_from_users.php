<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cashiers sign in with the browser and a PIN (../sylloo-erp-docs/erp/device-activation-plan.md §6.5): no password is
 * kept on the till any more. The column stays (nullable) and every stored hash is removed. The email may be empty too:
 * the office sends none for some members, and the till never makes one up.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('email')->nullable()->change();
        });

        DB::table('users')->update(['password' => null]);
    }

    public function down(): void
    {
        // The removed hashes can not come back; the column stays nullable.
    }
};
