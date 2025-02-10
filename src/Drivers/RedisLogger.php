<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Drivers;

use Xakki\LaraLog\CommonLogger;
use Xakki\LaraLog\ExtraProcessor;
use Illuminate\Support\Facades\Redis;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\RedisHandler;
use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;

class RedisLogger
{
    /**
     * @param array{connection: string, key?: string, capSize?: int, level?: string} $config
     * @return LoggerInterface
     */
    public function __invoke(array $config): LoggerInterface
    {
        if (! defined('STDERR')) {
            define('STDERR', fopen('php://stderr', 'wb'));
        }
        if (! defined('STDOUT')) {
            define('STDOUT', fopen('php://stdout', 'wb'));
        }
        /** @phpstan-ignore-next-line */
        $level = ! empty($config['level']) ? CommonLogger::toMonologLevel($config['level']) : \Monolog\Level::Info;

        $handler = new RedisHandler(
            redis: Redis::connection($config['connection'])->client(),
            key: $config['key'] ?? 'logs',
            level: $level,
            capSize: (int) ($config['capSize'] ?? 10000), // max logs in redis, 0 - unlimit
        );

        $handler->setFormatter(new LogstashFormatter(config('app.name')));
        $handler->pushProcessor(new LoadAverageProcessor());
        $handler->pushProcessor(new ProcessIdProcessor());
        $handler->pushProcessor(new ExtraProcessor());
        $handler->pushProcessor(new WebProcessor());
        $handler->pushProcessor(new PsrLogMessageProcessor());

        $logger = new CommonLogger('redis', [$handler]);
        $logger->setExceptionHandler(static function (\Throwable $e) {
            /** @phpstan-ignore-next-line */
            fwrite(\STDERR, '[' . date('Y-m-d h:i:s') . '] ' . get_class($e) . ' : ' . $e->getMessage());
            ini_set('error_log', '/proc/1/fd/2');
            error_log('* [' . date('Y-m-d h:i:s') . '] ' . get_class($e) . ' : ' . $e->getMessage(), 0);
        });
        $logger->level = $level;
        return $logger;
    }
}
