<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Drivers;

//use Monolog\Formatter\LogstashFormatter;
use Xakki\LaraLog\CommonLogger;
use Xakki\LaraLog\ExtraProcessor;
use Xakki\LaraLog\Formatters\CustomFormatter;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\ProcessIdProcessor;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;

/**
 * echo -n "test message" | nc -4u -w1 <host> <udp port>
 */
class SyslogUdp
{
    /**
     * @param array{host?: string, port?: int, level?: string} $config
     * @return LoggerInterface
     */
    public function __invoke(array $config): LoggerInterface
    {
        if (! defined('STDERR')) {
            define('STDERR', fopen('php://stderr', 'wb'));
        }
//        if (! defined('STDOUT')) {
//            define('STDOUT', fopen('php://stdout', 'wb'));
//        }
        /** @phpstan-ignore-next-line */
        $level = ! empty($config['level']) ? CommonLogger::toMonologLevel($config['level']) : \Monolog\Level::Info;

        $handler = new SyslogUdpHandler(
            host: $config['host'] ?? '127.0.0.1',
            port: $config['port'] ?? 5140,
            facility: LOG_LOCAL0,
            level: $level,
        );

        $handler->setFormatter(new CustomFormatter('Y-m-d\TH:i:s.uP'));
        $handler->pushProcessor(new LoadAverageProcessor());
        $handler->pushProcessor(new ProcessIdProcessor());
        $handler->pushProcessor(new ExtraProcessor());
        $handler->pushProcessor(new WebProcessor());
        $handler->pushProcessor(new PsrLogMessageProcessor());

        $logger = new CommonLogger('syslog', [$handler]);
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
