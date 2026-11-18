<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->unique()->primary();
            $table->tinyInteger('type')->index();
            $table->bigInteger('serial')->nullable();
            $table->foreignUlid('employee_id')->nullable();
            $table->foreignUlid('contact_group_id')->index();
            $table->string('contact_group_name')->nullable();
            $table->foreignUlid('desktop_pos_id')->index();
            $table->string('code')->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('name', 100)->nullable();
            $table->string('designation')->nullable();
            $table->string('business_name', 100)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->text('address')->nullable();
            $table->text('address1')->nullable();
            $table->foreignId('country_id')->nullable();
            $table->foreignId('state_id')->nullable();
            $table->foreignId('division_id')->nullable();
            $table->foreignId('district_id')->nullable();
            $table->foreignId('upazila_id')->nullable();
            $table->tinyInteger('is_delivery_location_different')->nullable();
            $table->string('delivery_first_name', 100)->nullable();
            $table->string('delivery_last_name', 100)->nullable();
            $table->string('delivery_name', 100)->nullable();
            $table->string('delivery_email', 100)->nullable();
            $table->string('delivery_phone', 30)->nullable();
            $table->string('delivery_mobile', 30)->nullable();
            $table->string('delivery_postcode', 10)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('delivery_address1')->nullable();
            $table->foreignId('delivery_country_id')->nullable();
            $table->foreignId('delivery_state_id')->nullable();
            $table->foreignId('delivery_division_id')->nullable();
            $table->foreignId('delivery_district_id')->nullable();
            $table->foreignId('delivery_upazila_id')->nullable();
            $table->string('image_url')->nullable();
            $table->string('default_language')->nullable();
            $table->string('default_currency')->nullable();
            $table->string('currency_code')->nullable();
            $table->decimal('currency_rate', 15, 8)->nullable();
            $table->decimal('credit_limit', 20, 6)->nullable();
            $table->decimal('opening_balance', 20, 6)->nullable();
            $table->tinyInteger('opening_type')->nullable();
            $table->decimal('credit', 20, 6)->nullable();
            $table->decimal('debit', 20, 6)->nullable();
            $table->decimal('balance', 20, 6)->nullable();
            $table->tinyInteger('device_type')->nullable();
            $table->string('sort')->nullable();
            $table->tinyInteger('status')->default(1)->index();
            $table->text('note')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->tinyInteger('is_walk_in')->nullable();
            $table->foreignUlid('created_by')->nullable();
            $table->foreignUlid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
