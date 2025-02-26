<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Illuminate\Log\Logger;

class NoContext
{
    public function __invoke(Logger $logger): void
    {
        $logger->withoutContext();
    }
}
