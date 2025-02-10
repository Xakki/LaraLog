<?php

namespace Xakki\LaraLog;

use Illuminate\Log\Logger;

class BaseContext
{
    public function __invoke(Logger $logger): void
    {
        $data = [
            'ver' => config('app.version'),
        ];
        if (request()) {
            $data['ip'] = request()->ip();
        }
        $logger->withContext($data);
    }
}
