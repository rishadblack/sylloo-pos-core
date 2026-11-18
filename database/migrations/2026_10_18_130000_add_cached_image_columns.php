<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Facades\Settings;

return new class extends Migration
{
    /**
     * Local copies of the ERP's images (see Rishadblack\SyllooPosCore\Services\ImageCache), so they show offline. Products also keep
     * their gallery and shipping methods their logo link as the ERP sends them, so both are pulled again in full
     * once. The images of rows pulled before are cached by the next `images:cache` run (also queued at app start).
     *
     * @var array<string, array<string, string>>
     */
    protected array $columns = [
        'brands' => ['image_path' => 'string'],
        'categories' => ['image_path' => 'string'],
        'products' => ['image_path' => 'string', 'images' => 'json', 'image_paths' => 'json'],
        'payment_methods' => ['logo_path' => 'string'],
        'shipping_methods' => ['logo_link' => 'text', 'logo_path' => 'string'],
        'users' => ['image_path' => 'string'],
    ];

    public function up(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $column => $type) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        $table->{$type}($column)->nullable();
                    }
                }
            });
        }

        rescue(function () {
            $lastSyncedByModel = Settings::get('last_synced_by_model', []) ?? [];
            unset($lastSyncedByModel['Product'], $lastSyncedByModel['ShippingMethod']);
            Settings::set('last_synced_by_model', $lastSyncedByModel);
        }, report: false);
    }

    public function down(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            $existing = array_values(array_filter(array_keys($columns), fn (string $column) => Schema::hasColumn($tableName, $column)));

            if ($existing !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
    }
};
