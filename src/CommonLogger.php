<?php

namespace Xakki\LaraLog;

use Illuminate\Support\Str;
use Monolog\JsonSerializableDateTimeImmutable as DateTimeImmutable;
use Monolog\Level;

/** @phpstan-ignore-next-line */
class CommonLogger extends \Monolog\Logger
{
    use TraitFileTrace;

    public Level $level;
    public const string REQUEST_HEADER_NAME = 'X-Request-ID';

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

    /**
     * Global context, may will changed only after release
     *
     * @return array<string, string|bool>
     */
    public static function getBaseContext(): array
    {
        static $context;
        if ($context) {
            return $context;
        }

        if (is_readable('/etc/hostname')) {
            $hostname = (string) file_get_contents('/etc/hostname');
        } else {
            $hostname = (string) gethostname();
        }

        $context = [
            'app_name' => env('APP_NAME'),
            'app_env' => env('APP_ENV'),
            'hostname' => $hostname,
        ];

        if (env('TIER')) {
            $context['tier'] = env('TIER');
        }
        if (env('APP_VERSION')) {
            $context['app_ver'] = env('APP_VERSION');
        }
        if (env('RELEASE_TAG')) {
            $context['release_tag'] = env('RELEASE_TAG');
        }
        if (env('RELEASE_TIME')) {
            $context['release_time'] = env('RELEASE_TIME');
        }
        if (env('CONTAINER_NAME')) {
            $context['container_name'] = env('CONTAINER_NAME');
        }
        if (! empty($_SERVER['argv'])) {
            $context['console_argv'] = implode(' ', $_SERVER['argv']);
        }
        return $context;
    }

    /**
     * @param \Throwable|string $e
     * @param array<string, mixed> $context
     * @return string
     */
    public static function appendContext(string|\Throwable $e, array &$context): string
    {
        self::contextTypeCorrector($context);

        if ($e instanceof \Throwable) {
            $context['exception_class'] = get_class($e);
            $context['exception_file'] = self::getFileLine($e->getTrace());
            $context['exception_code'] = $e->getCode();
            $context['exception_trace'] = self::traceToString($e->getTrace());
            if ($e->getPrevious()) {
                $context['exception_previous_message'] = $e->getPrevious()->getMessage();
                $context['exception_previous_class'] = get_class($e);
                $context['exception_previous_file'] = self::getRelativeFilePath($e->getPrevious()->getFile()) . ':' . $e->getPrevious()->getLine();
                $context['exception_previous_code'] = $e->getPrevious()->getCode();
                $context['exception_previous_trace'] = self::traceToString($e->getPrevious()->getTrace());
            }
            $message = $e->getMessage();
        } else {
            $message = $e;
        }
        $context['messageLen'] = mb_strlen($message);

        $context = array_merge($context, self::getBaseContext());
        return mb_substr($message, 0, 3024);
    }

    public function addRecord(int|Level $level, string $message, array $context = [], ?DateTimeImmutable $datetime = null): bool
    {
        $level = $this->toMonologLevel($level);

        if (! $this->level->includes($level)) {
            return true;
        }

        $message = self::appendContext($message, $context);

        return parent::addRecord($level, $message, $context, $datetime);
    }

    /**
     * @param array<string, mixed> $context
     * @return void
     */
    public static function contextTypeCorrector(array &$context): void
    {
        foreach ($context as $k => &$r) {
            switch ($k) {
                case \LOGGER_TIME:
                case \LOGGER_STATUS:
                case \LOGGER_COUNT:
                case \LOGGER_SUM:
                    $r = (int) $r;
                    continue 2;
                case \LOGGER_MCTIME:
                    $r = (float) $r;
                    continue 2;
                case \LOGGER_SUCCESS:
                    $r = (bool) $r;
                    continue 2;
            }
            if (
                str_contains($k, '_id') || str_contains($k, 'Id') || str_contains($k, '_cnt')
                || str_contains($k, '_count') || str_contains($k, 'Size')
            ) {
                $r = (int) $r;
            } elseif (str_contains($k, 'is_') || str_contains($k, 'has_') || str_contains($k, 'flag')) {
                $r = (bool) $r;
            } elseif (! is_string($r)) {
                $r = json_encode($r);
            }
        }
    }
}
