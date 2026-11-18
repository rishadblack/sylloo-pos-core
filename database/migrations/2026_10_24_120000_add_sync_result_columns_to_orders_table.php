<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the ERP answered for a pushed sale: received, received with issues (sent, needs checking at the office) or
 * already received, the issues it found, its own order id, the machine code of a refusal and when a sale the server
 * could not take because of a server problem is tried again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('sync_status', 30)->nullable()->after('sync_response');
            $table->json('sync_issues')->nullable()->after('sync_status');
            $table->string('sync_code', 50)->nullable()->after('sync_issues');
            $table->string('server_order_id', 26)->nullable()->after('sync_code');
            $table->timestamp('sync_retry_at')->nullable()->after('server_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_issues', 'sync_code', 'server_order_id', 'sync_retry_at']);
        });
    }
};
