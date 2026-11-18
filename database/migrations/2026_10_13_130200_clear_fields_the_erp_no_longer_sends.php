<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The ERP no longer sends a product's cost price and margin, nor a customer's delivery details,
     * notes and other personal details: forget what earlier pulls stored.
     */
    public function up(): void
    {
        DB::table('products')->update([
            'purchase_price' => 0,
            'purchase_vat' => null,
            'purchase_vat_amount' => 0,
            'net_purchase_price' => 0,
            'profit_margin' => 0,
        ]);

        DB::table('contacts')->update([
            'designation' => null,
            'postcode' => null,
            'country_id' => null,
            'state_id' => null,
            'division_id' => null,
            'district_id' => null,
            'upazila_id' => null,
            'is_delivery_location_different' => null,
            'delivery_first_name' => null,
            'delivery_last_name' => null,
            'delivery_name' => null,
            'delivery_email' => null,
            'delivery_phone' => null,
            'delivery_mobile' => null,
            'delivery_postcode' => null,
            'delivery_address' => null,
            'delivery_address1' => null,
            'delivery_country_id' => null,
            'delivery_state_id' => null,
            'delivery_division_id' => null,
            'delivery_district_id' => null,
            'delivery_upazila_id' => null,
            'image_url' => null,
            'note' => null,
        ]);
    }

    public function down(): void
    {
        //
    }
};
