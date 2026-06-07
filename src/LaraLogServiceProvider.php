<?php

declare(strict_types=1);

namespace Xakki\LaraLog;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Optional package provider. Register it to get:
 *  - default `logger.*` config (merged) + `vendor:publish --tag=laralog-config`
 *  - per-job request_id reset (spec §5.1) so a worker doesn't stamp every job with the
 *    first job's id (B7)
 *  - log_type capture handlers when logger.capture_handlers=true (spec §4.3.1, Option A)
 */
class LaraLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/logger.php', 'logger');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/logger.php' => config_path('logger.php'),
        ], 'laralog-config');

        // B7: a fresh request_id per job; without this a long-running worker reuses the
        // first job's id for everything, defeating correlation.
        Event::listen(JobProcessing::class, static function (): void {
            LogManager::resetRequestId();
        });

        if (config('logger.capture_handlers')) {
            LogType::installHandlers();
        }
    }
}
