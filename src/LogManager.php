<?php

namespace Xakki\LaraLog;

use Illuminate\Support\Str;
use Monolog\Level;

class LogManager extends \Illuminate\Log\LogManager
{
    use TraitFileTrace;

    protected int $messageLimit = 3024;

    public function __construct($app)
    {
        parent::__construct($app);
        if ($limit = config('logger.messageLimit')) {
            $this->messageLimit = (int) $limit;
        }
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function emergency($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Emergency, $message, $context);
        parent::emergency($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function alert($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Alert, $message, $context);
        parent::alert($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function critical($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Critical, $message, $context);
        parent::critical($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function error($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Error, $message, $context);
        parent::error($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function warning($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Warning, $message, $context);
        parent::warning($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function notice($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Notice, $message, $context);
        parent::notice($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Info, $message, $context);
        parent::info($message, $context);
    }

    /**
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function debug($message, array $context = []): void
    {
        $message = $this->appendContext(Level::Debug, $message, $context);
        parent::debug($message, $context);
    }

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<string, string|int|float|bool> $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
    {
        $message = $this->appendContext(Level::fromName($level), $message, $context);
        parent::log($level, $message, $context);
    }

    /**
     * @param Level $level
     * @param string|\Stringable $message
     * @param array<string, mixed> $context
     * @return string
     */
    public function appendContext(Level $level, string|\Stringable $message, array &$context): string
    {
        $e = null;
        if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
            $e = $context['exception'];
            unset($context['exception']);
        }

        // §4.3.1 log_type: a handler-set origin (trigger/fatal/exception) wins; an explicit
        // log carrying an exception is 'exception'; everything else is 'logger'.
        if (! isset($context['log_type'])) {
            $logType = LogType::current();
            if ($e !== null && $logType === LogType::LOGGER) {
                $logType = LogType::EXCEPTION;
            }
            $context['log_type'] = $logType;
        }

        self::contextTypeCorrector($context);

        if ($e) {
            $context['exception'] = get_class($e);
            $context['exception_code'] = (int) $e->getCode();
            $context['file'] = self::getRelativeFilePath($e->getFile()) . ':' . $e->getLine();
            $context['trace'] = self::traceToString($e->getTrace(), 30, false);
            if ($e->getPrevious()) {
                $context['exception_prev'] = get_class($e);
                $context['exception_prev_message'] = $e->getPrevious()->getMessage();
                $context['exception_prev_file'] = self::getRelativeFilePath($e->getPrevious()->getFile()) . ':' . $e->getPrevious()->getLine();
                $context['exception_prev_code'] = (int) $e->getPrevious()->getCode();
                $context['exception_prev_trace'] = self::traceToString($e->getPrevious()->getTrace(), 30, false);
            }
        }

        $context['message_len'] = mb_strlen($message);

        if (empty($context['file'])) {
            $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40), 1);
            $context['file'] = self::getFileLine($trace);
            if (empty($context['trace']) && $level->value >= Level::Warning->value) {
                $context['trace'] = self::traceToString($trace, self::traceDepthForLevel($level));
            }
        }
        if (config('logger.allow_memory', false)) {
            $context[\LOGGER_MEMORY_PEAK] = memory_get_peak_usage();
            $context[\LOGGER_MEMORY] = memory_get_usage();
        }

        $context['request_id'] = self::getOrCreateRequestId();

        return mb_substr($message, 0, $this->messageLimit);
    }

    /**
     * Trace depth (frames) by level, config-driven (spec §3.7). Replaces the old
     * broken int-vs-enum comparison block.
     */
    protected static function traceDepthForLevel(Level $level): int
    {
        /** @var array<string,int> $depth */
        $depth = (array) config('logger.trace.depth', ['warning' => 5, 'error' => 10, 'critical' => 20]);
        return match (true) {
            $level->value >= Level::Critical->value => (int) ($depth['critical'] ?? 20),
            $level->value >= Level::Error->value => (int) ($depth['error'] ?? 10),
            default => (int) ($depth['warning'] ?? 5),
        };
    }

    /**
     * Reset the per-request id so the next entrypoint (e.g. the next queue job) gets a
     * fresh one. Call between units of work; LaraLogServiceProvider wires this to
     * JobProcessing (spec §5.1, fixes B7).
     */
    public static function resetRequestId(): void
    {
        unset($_SERVER['HTTP_REQUEST_ID']);
        putenv('HTTP_REQUEST_ID');
    }

    public static function getOrCreateRequestId(): string
    {
        if (empty($_SERVER['HTTP_REQUEST_ID'])) {
            if (! empty($_SERVER['HTTP_X_REQUEST_ID'])) {
                $id = $_SERVER['HTTP_X_REQUEST_ID'];
            } else {
                $id = Str::uuid();
            }
            $_SERVER['HTTP_REQUEST_ID'] = (string) $id;
        }

        if (! env('HTTP_REQUEST_ID')) {
            putenv('HTTP_REQUEST_ID=' . $_SERVER['HTTP_REQUEST_ID']);
        }

        return $_SERVER['HTTP_REQUEST_ID'];
    }

    /**
     * @param array<string, mixed> $context
     * @return void
     */
    public static function contextTypeCorrector(array &$context): void
    {
        // §4.7: optional snake_case of keys (default off). Run BEFORE the reserved-key
        // switch — the LOGGER_* constants are already lowercase, so they still match.
        if (config('logger.snake_case', false)) {
            $normalized = [];
            foreach ($context as $k => $v) {
                $normalized[Str::snake((string) $k)] = $v;
            }
            $context = $normalized;
        }

        foreach ($context as $k => &$r) {
            // §2: mask credential-ish fields by key name before anything else.
            if (Redactor::shouldRedactKey((string) $k)) {
                $r = Redactor::MASK;
                continue;
            }
            switch ($k) {
                case \LOGGER_TIME:
                case \LOGGER_STATUS:
                case \LOGGER_COUNT:
                case \LOGGER_SUM:
                    $r = (int) $r;
                    continue 2;
                case \LOGGER_MCTIME:
                    $r = (float) $r;
                    continue 2;
                case \LOGGER_SUCCESS:
                    $r = (bool) $r;
                    continue 2;
            }
            if (
                str_contains($k, '_id') || str_contains($k, 'cnt')
                || str_contains($k, 'count') || str_contains($k, 'size')
            ) {
                $r = (int) $r;
            } elseif (str_contains($k, 'is_') || str_contains($k, 'has_') || str_contains($k, 'flag')) {
                $r = (bool) $r;
            } elseif (! is_string($r)) {
                $r = json_encode($r);
            }
        }
    }
}
