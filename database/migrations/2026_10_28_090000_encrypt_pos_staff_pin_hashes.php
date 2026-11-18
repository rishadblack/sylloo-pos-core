<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Support\ActivationKey;

/**
 * PIN hashes encrypted at rest (audit P2): `pos_staff.pin_hash` (bcrypt from the ERP) is now kept encrypted with the
 * per-install key (PosStaff casts it with TolerantEncrypted). The hashes saved before are encrypted once here; a value
 * that is already encrypted is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_staff')) {
            return;
        }

        DB::table('pos_staff')->whereNotNull('pin_hash')->orderBy('id')->each(function (object $staff) {
            $hash = (string) $staff->pin_hash;

            if ($hash === '' || ActivationKey::isEncryptedPayload($hash)) {
                return;
            }

            DB::table('pos_staff')->where('id', $staff->id)->update(['pin_hash' => Crypt::encryptString($hash)]);
        });
    }

    /**
     * Back to plain hashes; one that can not be decrypted here is dropped (the next staff list brings it back).
     */
    public function down(): void
    {
        if (! Schema::hasTable('pos_staff')) {
            return;
        }

        DB::table('pos_staff')->whereNotNull('pin_hash')->orderBy('id')->each(function (object $staff) {
            $hash = (string) $staff->pin_hash;

            if (! ActivationKey::isEncryptedPayload($hash)) {
                return;
            }

            DB::table('pos_staff')->where('id', $staff->id)->update(['pin_hash' => rescue(fn () => Crypt::decryptString($hash), null, false)]);
        });
    }
};
