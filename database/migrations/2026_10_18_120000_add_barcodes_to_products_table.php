<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Facades\Settings;

return new class extends Migration
{
    /**
     * Every barcode of a product as the ERP sends it, so a scan finds the product by any of its barcodes and not
     * only by its product code. Products are pulled again in full once, to fill the new column.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'barcodes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('barcodes')->nullable()->after('code');
            });
        }

        rescue(function () {
            $lastSyncedByModel = Settings::get('last_synced_by_model', []) ?? [];
            unset($lastSyncedByModel['Product']);
            Settings::set('last_synced_by_model', $lastSyncedByModel);
        }, report: false);
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'barcodes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('barcodes');
            });
        }
    }
};
