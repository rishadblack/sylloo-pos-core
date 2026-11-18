<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approvals on the spot and their record (audit Phase 3):
 *
 * - `pos_staff`: who may work on this terminal, with their POS permissions, discount limit and PIN hash, from the ERP
 *   (StaffDirectory), so a manager's PIN is checked offline too;
 * - `audit_logs`: what cashiers did beyond selling and who approved it, sent with the heartbeat (AuditTrail);
 * - a line's price change (`order_items.price_change`) and the order discount's approver, sent with the sale;
 * - a card payment's last four digits (`transactions.card_last4`); its slip number is the payment's `ref`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_staff', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->string('pin_hash')->nullable();
            $table->json('permissions')->nullable();
            $table->unsignedTinyInteger('max_discount_percent')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('action', 40)->index();
            $table->ulid('user_id')->nullable();
            $table->ulid('approved_by')->nullable();
            $table->string('order_code', 100)->nullable();
            $table->string('reason', 191)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('reported_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->json('price_change')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->ulid('discount_approved_by')->nullable();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('card_last4', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('card_last4');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('discount_approved_by');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('price_change');
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('pos_staff');
    }
};
