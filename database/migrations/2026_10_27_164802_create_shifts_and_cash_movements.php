<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shifts (audit Phase 3): each cashier opens their own shift with a float before selling and closes it with a cash
 * count (ShiftRegister). A shift is a session of the ERP's cash register for this terminal (erp:
 * docs/account-daily-book.md §3b.2), opened and closed there when the till is online (RegisterSync):
 *
 * - `shifts`: the float, then at close the expected and counted cash, the difference and the Z figures (`totals`),
 *   with the ERP register session and how far it is (`register_status`);
 * - `cash_movements`: cash put in or taken out of the drawer during a shift, with the reason, its Daily book category
 *   and who approved it, pushed as `CashMovement`;
 * - the ERP's cash registers of the outlet, their sessions and the Daily book categories (pulled);
 * - the shift a sale was made in (`orders.shift_id`);
 * - the kind of a payment method from the ERP (`payment_methods.kind`: cash, card, mobile…), to count the drawer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedInteger('number')->index();
            $table->ulid('user_id')->index();
            $table->timestamp('opened_at');
            $table->decimal('opening_float', 20, 6)->default(0);
            $table->timestamp('closed_at')->nullable()->index();
            $table->decimal('expected_cash', 20, 6)->nullable();
            $table->decimal('counted_cash', 20, 6)->nullable();
            $table->decimal('difference', 20, 6)->nullable();
            $table->json('totals')->nullable();
            $table->string('close_note', 191)->nullable();
            $table->ulid('cash_register_id')->nullable();
            $table->ulid('cash_register_session_id')->nullable();
            $table->string('register_status', 20)->default('to_open')->index();
            $table->string('register_message', 500)->nullable();
            $table->decimal('erp_expected_cash', 20, 6)->nullable();
            $table->decimal('erp_difference', 20, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('shift_id')->index();
            $table->string('type', 10);
            $table->decimal('amount', 20, 6);
            $table->string('reason', 191)->nullable();
            $table->ulid('daily_category_id')->nullable();
            $table->ulid('user_id')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->timestamp('synced_at')->nullable()->index();
            $table->string('sync_status', 30)->nullable();
            $table->string('sync_message', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->ulid('desktop_pos_id')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
        });

        Schema::create('cash_register_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('cash_register_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('code', 100)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('daily_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->text('name')->nullable();
            $table->tinyInteger('flow_type')->index();
            $table->tinyInteger('party_kind')->default(0);
            $table->ulid('vat_id')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('color', 30)->nullable();
            $table->boolean('is_quick')->default(false);
            $table->integer('sort_order')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('shift_id')->nullable()->index();
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('kind', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shift_id']);
            $table->dropColumn('shift_id');
        });

        Schema::dropIfExists('daily_categories');
        Schema::dropIfExists('cash_register_sessions');
        Schema::dropIfExists('cash_registers');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shifts');
    }
};
