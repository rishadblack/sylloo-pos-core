<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each cashier keeps the ERP sign-in token issued to them on this terminal (encrypted by the
     * User model) and when it expires, instead of one token shared by everyone in the app settings.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('api_token')->nullable()->after('password');
            $table->timestamp('token_expires_at')->nullable()->after('api_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['api_token', 'token_expires_at']);
        });
    }
};
