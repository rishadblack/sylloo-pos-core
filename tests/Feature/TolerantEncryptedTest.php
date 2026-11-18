<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Rishadblack\SyllooPosCore\Models\User;

/*
 * The till's encrypted columns (a cashier's API token, a staff PIN hash) use the models' encrypter: Crypt on the PC
 * till, the Keystore's key on the Android till (Model::encryptUsing()).
 */

afterEach(fn () => Model::encryptUsing(null));

it('encrypts with the app key while the till sets no encrypter', function () {
    $user = User::factory()->create(['api_token' => 'token-1']);
    $stored = DB::table('users')->where('id', $user->id)->value('api_token');

    expect($stored)->not->toBe('token-1')
        ->and(Crypt::decryptString($stored))->toBe('token-1')
        ->and($user->fresh()->api_token)->toBe('token-1');
});

it('encrypts with the encrypter the till sets for its models', function () {
    $phoneKey = new Encrypter(random_bytes(32), 'aes-256-cbc');
    Model::encryptUsing($phoneKey);

    $user = User::factory()->create(['api_token' => 'token-2']);
    $stored = DB::table('users')->where('id', $user->id)->value('api_token');

    expect($phoneKey->decryptString($stored))->toBe('token-2')
        ->and(fn () => Crypt::decryptString($stored))->toThrow(DecryptException::class)
        ->and($user->fresh()->api_token)->toBe('token-2');
});

it('reads a value it can not decrypt as nothing', function () {
    $user = User::factory()->create(['api_token' => 'token-3']);
    Model::encryptUsing(new Encrypter(random_bytes(32), 'aes-256-cbc'));

    expect($user->fresh()->api_token)->toBeNull();
});
