<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['app_name'] = config('app.name');
        $record->extra['app_env'] = config('app.env');
        $record->extra['app_ver'] = config('app.version');
        $record->extra['log_ver'] = LOGGER_VER;

        if (env('TIER')) {
            $record->extra['tier'] = env('TIER');
        }
        if (env('RELEASE_TAG')) {
            $record->extra['release_tag'] = env('RELEASE_TAG');
        }
        if (env('RELEASE_TIME')) {
            $record->extra['release_time'] = env('RELEASE_TIME');
        }
        if (env('CONTAINER_NAME')) {
            $record->extra['container_name'] = env('CONTAINER_NAME');
        }
        if (env('HOST_IP')) {
            $record->extra['host_ip'] = env('HOST_IP');
        }
        if (env('HOST_NAME')) {
            $record->extra['host_name'] = env('HOST_NAME');
        }
        if (! empty($_SERVER['argv'])) {
            $record->extra['console_argv'] = implode(' ', $_SERVER['argv']);
        }

        return $record;
    }

}
