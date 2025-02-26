<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Drivers;

use Illuminate\Support\Facades\Redis;
use Monolog\Formatter\LogstashFormatter;
use Monolog\Handler\RedisHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class RedisLogger
{
    /**
     * @param array{connection: string, key?: string, capSize?: int, level?: string} $config
     * @return LoggerInterface
     */
    public function __invoke(array $config): LoggerInterface
    {
        /** @phpstan-ignore-next-line */
        $level = ! empty($config['level']) ? Logger::toMonologLevel($config['level']) : \Monolog\Level::Info;

        $handler = new RedisHandler(
            redis: Redis::connection($config['connection'])->client(),
            key: $config['key'] ?? 'logs',
            level: $level,
            capSize: (int) ($config['capSize'] ?? 10000), // max logs in redis, 0 - unlimit
        );

        $handler->setFormatter(new LogstashFormatter(config('app.name')));

        return new Logger('redis', [$handler]);
    }
}
