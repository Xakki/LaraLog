<?php

declare(strict_types=1);

namespace Xakki\LaraLog\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    /**
     * Process-stable fields (spec §4.2) — built ONCE from `config('logger.extra')`, the whole
     * array verbatim (empty values dropped). Sourcing from config means env() is read at
     * config-load time and survives `php artisan config:cache` (B9). Requires the config to be
     * present — register LaraLogServiceProvider (merges package defaults) or publish
     * config/logger.php; otherwise `extra` is empty.
     *
     * @var array<string, mixed>
     */
    private array $extra;

    public function __construct()
    {
        $extra = array_filter(
            (array) config('logger.extra', []),
            static fn ($v): bool => $v !== null && $v !== '',
        );

        // argv is stable for the process lifetime -> compute here, not per record.
        if (! empty($_SERVER['argv'])) {
            $extra['console_argv'] = implode(' ', $_SERVER['argv']);
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

        return $record;
    }
}
