<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    /**
     * Process-stable fields (spec §4.2) — computed ONCE. Prefer config (captured by
     * `php artisan config:cache`, so it survives in prod), fall back to env() so the
     * processor still works standalone when LaraLogServiceProvider isn't registered (B9).
     * The config-cache-safe path requires publishing config/logger.php.
     *
     * @var array<string, mixed>
     */
    private array $extra;

    public function __construct()
    {
        // Key-absent default doesn't fire when mergeConfigFrom sets the key present-but-null,
        // so use ?: rather than config('logger.version', config('app.version')).
        $extra = [
            'app_name' => config('app.name'),
            'app_env'  => config('app.env'),
            'app_ver'  => config('logger.version') ?: config('app.version'),
            'log_ver'  => LOGGER_VER,
        ];

        $optional = [
            'tier'           => ['logger.tier', 'TIER'],
            'release_tag'    => ['logger.release_tag', 'RELEASE_TAG'],
            'release_time'   => ['logger.release_time', 'RELEASE_TIME'],
            'container_name' => ['logger.container_name', 'CONTAINER_NAME'],
            'host_ip'        => ['logger.host_ip', 'HOST_IP'],
            'host_name'      => ['logger.host_name', 'HOST_NAME'],
        ];
        foreach ($optional as $field => [$cfgKey, $envKey]) {
            if ($value = (config($cfgKey) ?: env($envKey))) {
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
