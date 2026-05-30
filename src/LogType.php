<?php

declare(strict_types=1);

namespace Xakki\LaraLog;

/**
 * Classifies who initiated a log (spec §4.3.1, `log_type`):
 *   logger    — explicit call in code (default)
 *   trigger   — PHP runtime error handler (set_error_handler / trigger_error)
 *   exception — uncaught exception handler, or explicit log with ['exception' => $e]
 *   fatal     — script crash caught in register_shutdown_function
 *
 * Origin is a process-scoped flag set by the chained handlers (Option A) and read by
 * LogManager::appendContext(). Handlers are installed only when logger.capture_handlers=true.
 */
final class LogType
{
    public const LOGGER = 'logger';
    public const TRIGGER = 'trigger';
    public const EXCEPTION = 'exception';
    public const FATAL = 'fatal';

    private static ?string $origin = null;

    public static function set(?string $type): void
    {
        self::$origin = $type;
    }

    public static function current(): string
    {
        return self::$origin ?? self::LOGGER;
    }

    public static function reset(): void
    {
        self::$origin = null;
    }

    /**
     * Install error/exception/shutdown handlers, each CHAINED to the previously
     * registered (framework) handler so the host app's error rendering / reporting
     * keeps working. Call once, from a service provider boot.
     *
     * Caveat: `fatal` tagging is best-effort. The framework usually registers its
     * shutdown handler earlier, so a fatal may be logged via the exception path
     * (tagged `exception`) before our shutdown function runs. See card 0001 / docs.
     */
    public static function installHandlers(): void
    {
        $prevError = null;
        $prevError = set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$prevError): bool {
                self::$origin = self::TRIGGER;
                try {
                    // Defer to the framework's handler; if none, let PHP's internal handler run.
                    return $prevError ? (bool) ($prevError)($errno, $errstr, $errfile, $errline) : false;
                } finally {
                    self::$origin = null;
                }
            }
        );

        $prevException = null;
        $prevException = set_exception_handler(
            static function (\Throwable $e) use (&$prevException): void {
                self::$origin = self::EXCEPTION;
                if ($prevException) {
                    ($prevException)($e);
                }
                // No reset: the process terminates after an uncaught exception.
            }
        );

        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::$origin = self::FATAL;
            }
        });
    }
}
