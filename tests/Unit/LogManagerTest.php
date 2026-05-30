<?php declare(strict_types=1);

namespace Xakki\LaraLogTests\Unit;

use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\WebProcessor;
use Xakki\LaraLog\LogManager;
use Xakki\LaraLog\Processor\ExtraProcessor;
use Xakki\LaraLog\Redactor;
use Xakki\LaraLog\TraitFileTrace;
use Xakki\LaraLogTests\AbstractTestCase;

class LogManagerTest extends AbstractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Static caches leak across tests in one process — reset before each.
        Redactor::flush();
        TraitFileTrace::flushTraceConfig();
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extraConfig applied AFTER the app is built (createApplication
     *                                           rebinds the global container, wiping prior config)
     */
    private function logToFile(string $level, string $message, array $context = [], string $minLevel = 'debug', array $extraConfig = []): string
    {
        $logManager = new LogManager($this->createApplication());
        config(array_merge([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->getLogPath(),
            'logging.channels.single.level' => $minLevel,
        ], $extraConfig));
        $this->clearLog();
        $logManager->{$level}($message, $context);
        return $this->getLog();
    }

    public function testInit(): void
    {
        // appendContext now always adds log_type + request_id, and (after the B2 fix) a trace
        // at >= warning — so assert on stable substrings instead of an exact context match.
        $log = $this->logToFile('warning', 'Test message', ['test' => 'context message'], 'notice');

        $this->assertStringContainsString('testing.WARNING: Test message', $log);
        $this->assertStringContainsString('"test":"context message"', $log);
        $this->assertStringContainsString('"message_len":12', $log);
        $this->assertStringContainsString('"log_type":"logger"', $log);
        $this->assertStringContainsString('"request_id":', $log);
        $this->assertStringContainsString('LogManagerTest.php:', $log);
    }

    public function testLogTypeExceptionOnExplicitException(): void
    {
        $log = $this->logToFile('error', 'boom', ['exception' => new \RuntimeException('x')]);

        $this->assertStringContainsString('"log_type":"exception"', $log);
        $this->assertStringContainsString('"exception":"RuntimeException"', $log);
    }

    public function testCredentialRedactionByKey(): void
    {
        $log = $this->logToFile('warning', 'login', [
            'password' => 'hunter2',
            'api_key'  => 'sk-live-123',
            'order_id' => '42',
        ]);

        $this->assertStringContainsString('"password":"***"', $log);
        $this->assertStringContainsString('"api_key":"***"', $log);
        $this->assertStringNotContainsString('hunter2', $log);
        $this->assertStringNotContainsString('sk-live-123', $log);
        // Non-secret typed fields still pass through (order_id -> int).
        $this->assertStringContainsString('"order_id":42', $log);
    }

    public function testSnakeCaseOptIn(): void
    {
        $log = $this->logToFile('warning', 'msg', ['orderId' => '7'], 'debug', ['logger.snake_case' => true]);

        $this->assertStringContainsString('"order_id":7', $log);
        $this->assertStringNotContainsString('orderId', $log);
    }

    public function testTraceAttachedByLevel(): void
    {
        $notice = $this->logToFile('notice', 'just a note');
        $this->assertStringNotContainsString('"trace":', $notice);

        $error = $this->logToFile('error', 'a problem');
        $this->assertStringContainsString('"trace":', $error);
    }

    public function testTraceDepthIsConfigurable(): void
    {
        // depth 1 -> traceToString appends '***' after the first rendered frame. If B2
        // regressed (depth hardcoded to 20) this shallow stack would NOT be truncated.
        $log = $this->logToFile('error', 'deep', [], 'debug', [
            'logger.trace.depth' => ['warning' => 5, 'error' => 1, 'critical' => 20],
        ]);
        $this->assertStringContainsString('***', $log);
    }

    public function testExtraProcessorDumpsConfigExtra(): void
    {
        // ExtraProcessor copies config('logger.extra') verbatim; empty values are dropped.
        $this->createApplication();
        config(['logger.extra' => [
            'tier'        => 'prod',
            'release_tag' => 'v1.2.3',
            'log_ver'     => '0.3',
            'host_name'   => null,   // unset -> must NOT appear
        ]]);

        $record = new \Monolog\LogRecord(
            new \DateTimeImmutable(),
            'test',
            \Monolog\Level::Info,
            'hello',
        );
        $out = (new ExtraProcessor())($record);

        $this->assertSame('prod', $out->extra['tier']);
        $this->assertSame('v1.2.3', $out->extra['release_tag']);
        $this->assertSame('0.3', $out->extra['log_ver']);
        $this->assertArrayNotHasKey('host_name', $out->extra);
    }


    public function testSyslog(): void
    {
        $logManager = new LogManager($this->createApplication());
        $this->clearLog();

        config([
            'logging.default' => 'syslog',
            'logging.channels.syslog' => [
                'driver' => 'monolog',
                'handler'    => \Monolog\Handler\SyslogUdpHandler::class,
                'handler_with' => [
                    'host' => '127.0.0.1',
                    'port' => 15140,
                    'facility' => LOG_LOCAL0,
                    'level' => env('LOG_LEVEL', 'info'),
                ],
                'formatter' => \Xakki\LaraLog\Formatters\CustomFormatter::class,
                'formatter_with' => [
                    'dateFormat' => 'Y-m-d\TH:i:s.uP',
                ],
                'processors' => [
                    \Xakki\LaraLog\Processor\ExtraProcessor::class,
                    LoadAverageProcessor::class,
                    WebProcessor::class,
                ],
            ],
        ]);

        $mess = 'Test message';
        $context = ['test'  => 'context message'];
        $logManager->warning($mess, $context);

        $this->assertTrue(true);
    }
}
