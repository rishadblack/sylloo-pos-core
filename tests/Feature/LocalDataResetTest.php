<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rishadblack\SyllooPosCore\Models\User;
use Rishadblack\SyllooPosCore\Services\LocalDataReset;

/*
 * A fresh start empties every table but the ones the till keeps (`reset.kept_tables`): the Android till keeps its
 * device store, which holds the activation.
 */

beforeEach(function () {
    Schema::create('phone_settings', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });

    DB::table('phone_settings')->insert(['key' => 'activation', 'value' => 'kept']);
    User::factory()->create();
});

it('keeps only the migrations by default', function () {
    $migrations = DB::table('migrations')->count();

    app(LocalDataReset::class)->handle();

    expect(DB::table('phone_settings')->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(DB::table('migrations')->count())->toBe($migrations)
        ->and(LocalDataReset::keptTables())->toBe(['migrations']);
});

it('leaves the tables the till keeps as they are', function () {
    config(['sylloo-pos-core.reset.kept_tables' => ['phone_settings']]);
    $migrations = DB::table('migrations')->count();

    app(LocalDataReset::class)->handle();

    expect(DB::table('phone_settings')->value('value'))->toBe('kept')
        ->and(DB::table('migrations')->count())->toBe($migrations)
        ->and(User::query()->count())->toBe(0)
        ->and(LocalDataReset::keptTables())->toBe(['migrations', 'phone_settings']);
});
