<?php

namespace Xakki\LaraLog;

use Illuminate\Log\Logger;

class BaseContext
{
    public function __invoke(Logger $logger): void
    {
        $data = [
            'app_ver' => config('app.version'),
        ];
        if (request()) {
            $data['app_ip'] = request()->ip();
        }
        $logger->withContext($data);
    }
}
