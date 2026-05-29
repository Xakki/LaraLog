<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    /**
     * Process-stable fields (spec §4.2) — computed ONCE. Sourced from config (not env() at
     * log-time): config is captured by `php artisan config:cache`, so these survive in prod;
     * a runtime env() returns null once config is cached (B9).
     *
     * @var array<string, mixed>
     */
    private array $extra;

    public function __construct()
    {
        $extra = [
            'app_name' => config('app.name'),
            'app_env'  => config('app.env'),
            'app_ver'  => config('logger.version', config('app.version')),
            'log_ver'  => LOGGER_VER,
        ];

        foreach ([
            'tier'           => 'logger.tier',
            'release_tag'    => 'logger.release_tag',
            'release_time'   => 'logger.release_time',
            'container_name' => 'logger.container_name',
            'host_ip'        => 'logger.host_ip',
            'host_name'      => 'logger.host_name',
        ] as $field => $cfgKey) {
            if ($value = config($cfgKey)) {
                $extra[$field] = $value;
            }
        }

        $this->extra = $extra;
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        foreach ($this->extra as $key => $value) {
            $record->extra[$key] = $value;
        }

        if (! empty($_SERVER['argv'])) {
            $record->extra['console_argv'] = implode(' ', $_SERVER['argv']);
        }

        return $record;
    }
}
