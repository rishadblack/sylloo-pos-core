<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash handed over to the main office at the close of a shift (ShiftRegister::close, ../sylloo-erp-docs/erp/
 * cash-handover.md "From the till's Close shift"): the amount the cashier hands over (the rest of the counted cash
 * stays in the drawer as the closing float), sent with the register close (RegisterSync), and the main-office cash
 * account the office booked it to, from its answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->decimal('handover_amount', 20, 6)->default(0)->after('counted_cash');
            $table->string('handover_account', 191)->nullable()->after('handover_amount');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['handover_amount', 'handover_account']);
        });
    }
};
