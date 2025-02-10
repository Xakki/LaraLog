<?php

namespace Xakki\LaraLog;

use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class QueueHeartBeat
{
    public function serviceProviderBoot(): void
    {
        $tickLastTime = 0;
        Queue::popUsing('default', function ($popJobCallback, $queue) use (&$tickLastTime) {
            $tick = (int) env('TICK_LOG', 120);
            if ($tick && (time() - $tickLastTime) > $tick) {
                logger()?->info('heartbeat: ' . $queue, [\LOGGER_MONITORING => 'queue', \LOGGER_COUNT => 0]);
                $tickLastTime = time();
            }
            foreach (explode(',', $queue) as $queue) {
                if (! is_null($job = $popJobCallback($queue))) {
                    return $job;
                }
            }
            return null;
        });

        Event::listen(function (WorkerStopping $event) {
            logger()?->info('Queue stop signal: ' . $event->status, [\LOGGER_MONITORING => 'queue-stop', \LOGGER_STATUS => $event->status]);
        });
    }
}
