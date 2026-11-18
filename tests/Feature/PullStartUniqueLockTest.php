<?php

use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Rishadblack\SyllooPosCore\Facades\Settings;
use Rishadblack\SyllooPosCore\Jobs\PullStart;

/*
 * A pull is unique while it waits or runs (uniqueFor 600 s). Laravel frees that lock after the job ran, under the key
 * of uniqueId() as it is then. handle() fills in the sync dates and the pricing models, so the key must stay the one
 * the pull was queued with: otherwise the lock stays for ten minutes and every pull asked for meanwhile (the cashier's
 * sign-in, Sync now, the heartbeat) is silently dropped.
 */

beforeEach(function () {
    config(['queue.default' => 'sync']);
    Settings::set('last_synced_by_model', ['Product' => '2026-11-18 07:00:00']);
});

it('frees its unique lock after it ran, so the next pull is queued', function (?string $models, mixed $date, bool $fullPull) {
    // No cashier is signed in yet: the pull waits (the till asks again at sign-in).
    PullStart::dispatch(date: $date, models: $models, fullPull: $fullPull);

    $lock = Cache::lock(UniqueLock::getKey(new PullStart($date, $models, fullPull: $fullPull)), 10);

    expect($lock->get())->toBeTrue();
    $lock->release();
})->with([
    'every model from the kept sync dates' => [null, null, false],
    'products, with their pricing models' => ['Product', null, false],
    'one date for every model' => [null, '2026-11-01 00:00:00', false],
    'a full pull' => [null, null, true],
]);

it('keeps the unique id it was queued with while it runs', function () {
    $pull = new PullStart(models: 'Product');
    $queuedWith = $pull->uniqueId();

    $pull->handle();

    expect($pull->uniqueId())->toBe($queuedWith);
});
