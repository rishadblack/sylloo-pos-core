<?php

namespace Rishadblack\SyllooPosCore\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobFinished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $jobId;

    public $status;

    public function __construct($jobId, $status = 'success')
    {
        $this->jobId = $jobId;
        $this->status = $status;
    }

    public function broadcastOn()
    {
        return [new Channel('nativephp')];
    }

    public function broadcastAs(): string
    {
        return 'job_finished';
    }

    public function broadcastWith(): array
    {
        return [
            'jobId' => $this->jobId,
            'status' => $this->status,
        ];
    }
}
