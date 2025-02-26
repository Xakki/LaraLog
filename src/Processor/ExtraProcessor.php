<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    public const string REQUEST_HEADER_NAME = 'X-Request-ID';
    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['memory_peak'] = memory_get_peak_usage();
        $record->extra['memory_usage'] = memory_get_usage();
        $record->extra['request_id'] = self::getOrCreateRequestId();

        static $hostname;
        if (!$hostname) {
            if (is_readable('/etc/hostname')) {
                $hostname = (string) file_get_contents('/etc/hostname');
            } else {
                $hostname = (string) gethostname();
            }
        }

        $record->extra['app_name'] = env('APP_NAME');
        $record->extra['app_env'] = env('APP_ENV');
        $record->extra['app_ver'] = config('app.version');
        $record->extra['log_ver'] = LOGGER_VER;
        $record->extra['host'] = $hostname;

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
        if (! empty($_SERVER['argv'])) {
            $record->extra['console_argv'] = implode(' ', $_SERVER['argv']);
        }
        return $record;
    }

    public static function getOrCreateRequestId(): string
    {
        if (empty($_SERVER['HTTP_REQUEST_ID'])) {
            if (! empty($_SERVER['HTTP_X_REQUEST_ID'])) {
                $id = $_SERVER['HTTP_X_REQUEST_ID'];
            } else {
                $id = Str::uuid();
            }
            $_SERVER['HTTP_REQUEST_ID'] = (string) $id;
        }

        if (! env('HTTP_REQUEST_ID')) {
            putenv('HTTP_REQUEST_ID=' . $_SERVER['HTTP_REQUEST_ID']);
        }

        return $_SERVER['HTTP_REQUEST_ID'];
    }
}
