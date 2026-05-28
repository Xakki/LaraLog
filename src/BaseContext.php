<?php

namespace Xakki\LaraLog;

use Illuminate\Log\Logger;

class BaseContext
{
    public function __invoke(Logger $logger): void
    {
        $data = [];
        if (request()->ip()) {
            $data['remote_ip'] = request()->ip();
        }
        $logger->withContext($data);
    }
}
