<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Database\Seeders\SettingSeeder;

return new class extends Migration
{
    /**
     * Store and receipt settings, one row per parameter.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->string('parameter')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Installed apps only run migrations, never seeders, so the defaults are inserted here.
        (new SettingSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
