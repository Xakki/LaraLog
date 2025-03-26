<?php declare(strict_types=1);

namespace AppTests\Unit;

use Monolog\Processor\LoadAverageProcessor;
use Monolog\Processor\WebProcessor;
use Xakki\LaraLog\LogManager;
use Xakki\LaraLogTests\AbstractTestCase;

class LogManagerTest extends AbstractTestCase
{

    public function testInit(): void
    {
        $logManager = new LogManager($this->createApplication());

        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->getLogPath(),
            'logging.channels.single.level' => 'notice',
        ]);

        $this->clearLog();

        $mess = 'Test message';
        $context = ['test'  => 'context message'];
        $logManager->warning($mess, $context);

        $context['messageLen'] = strlen($mess);
        $context['file'] = '/tests/Unit/LogManagerTest.php:27';
        $context = json_encode($context);

        $this->assertMatchesRegularExpression("/\[[\d\-\s\:]+\] testing\.WARNING\: $mess $context \n/u",  $this->getLog());
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
