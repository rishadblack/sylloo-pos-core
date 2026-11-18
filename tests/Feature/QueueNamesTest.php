<?php

use Rishadblack\SyllooPosCore\Contracts\QueueWorkers;
use Rishadblack\SyllooPosCore\Jobs\CacheErpImages;
use Rishadblack\SyllooPosCore\Jobs\PullErpJob;
use Rishadblack\SyllooPosCore\Jobs\PullStaff;
use Rishadblack\SyllooPosCore\Jobs\PullStart;
use Rishadblack\SyllooPosCore\Jobs\PullTerminalProfile;
use Rishadblack\SyllooPosCore\Jobs\PushErpJob;
use Rishadblack\SyllooPosCore\Jobs\RegisterSync;
use Rishadblack\SyllooPosCore\Jobs\SendHeartbeat;
use Rishadblack\SyllooPosCore\Services\LocalDataReset;
use Rishadblack\SyllooPosCore\Services\TerminalReleaseSteps;
use Rishadblack\SyllooPosCore\Support\TillQueues;

/*
 * The queues of the till's jobs and workers come from `sylloo-pos-core.queues`: the PC till keeps a queue per kind of
 * work, the Android till (one worker, the default queue) maps them all to `default`.
 */

/**
 * @return array<string, ?string>
 */
function tillJobQueues(): array
{
    return [
        'push' => (new PushErpJob)->queue,
        'register' => (new RegisterSync)->queue,
        'heartbeat' => (new SendHeartbeat)->queue,
        'pull' => (new PullErpJob('pull', 'Product'))->queue,
        'pull start' => (new PullStart)->queue,
        'staff' => (new PullStaff)->queue,
        'profile' => (new PullTerminalProfile)->queue,
        'images' => (new CacheErpImages)->queue,
    ];
}

function recordingQueueWorkers(): object
{
    $workers = new class implements QueueWorkers
    {
        /** @var list<string> */
        public array $calls = [];

        public function up(string $queue): void
        {
            $this->calls[] = "up:$queue";
        }

        public function down(string $queue): void
        {
            $this->calls[] = "down:$queue";
        }
    };

    app()->instance(QueueWorkers::class, $workers);

    return $workers;
}

function oneQueue(): void
{
    config(['sylloo-pos-core.queues' => ['push' => 'default', 'pull' => 'default', 'images' => 'default', 'default' => 'default']]);
}

it('keeps the queue of each kind of work by default, as the PC till runs them', function () {
    expect(tillJobQueues())->toBe([
        'push' => 'push', 'register' => 'push', 'heartbeat' => 'push',
        'pull' => 'pull', 'pull start' => 'pull', 'staff' => 'pull', 'profile' => 'pull',
        'images' => 'images',
    ])->and(TillQueues::workers())->toBe(['default', 'images']);
});

it('puts every job on the queue the till names', function () {
    oneQueue();

    expect(array_values(array_unique(tillJobQueues())))->toBe(['default'])
        ->and(TillQueues::workers())->toBe(['default']);
});

it('falls back to the kind of work when a queue is not named', function () {
    config(['sylloo-pos-core.queues' => ['push' => '']]);

    expect(TillQueues::name('push'))->toBe('push')
        ->and(TillQueues::name('pull'))->toBe('pull');
});

it('starts and stops the workers the till names', function () {
    $workers = recordingQueueWorkers();

    app(LocalDataReset::class)->handle();
    app(TerminalReleaseSteps::class)->afterClean();

    expect($workers->calls)->toBe(['down:default', 'down:images', 'up:default', 'up:images']);

    oneQueue();
    $workers->calls = [];

    app(LocalDataReset::class)->handle();
    app(TerminalReleaseSteps::class)->afterClean();

    expect($workers->calls)->toBe(['down:default', 'up:default', 'up:default']);
});
