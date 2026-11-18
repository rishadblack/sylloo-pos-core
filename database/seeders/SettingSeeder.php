<?php

namespace Rishadblack\SyllooPosCore\Database\Seeders;

use Illuminate\Database\Seeder;
use Rishadblack\SyllooPosCore\Models\Setting;

class SettingSeeder extends Seeder
{
    /**
     * Insert every default general, store and receipt setting that has no row yet. Saved values are never
     * overwritten, so this is safe to run again (e.g. when new settings are added).
     */
    public function run(): void
    {
        foreach (Setting::defaults() as $parameter => $value) {
            Setting::firstOrCreate(['parameter' => $parameter], ['value' => $value]);
        }
    }
}
