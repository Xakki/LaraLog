<?php

namespace Xakki\LaraLog\Formatters;

use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\PsrHandler;

class CustomizeFormatter
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            /** @var PsrHandler $handler */
            $handler->setFormatter(new LineFormatter(
                '[%datetime%] %channel%.%level_name%: %message% %context% %extra%' . PHP_EOL
            ));
        }
    }
}
