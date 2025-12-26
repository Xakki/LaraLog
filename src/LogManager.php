<?php

namespace Xakki\LaraLog;

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

        $context['messageLen'] = mb_strlen($message);

        if (empty($context['file'])) {
            $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40), 1);
            $context['file'] = self::getFileLine($trace);
            /** @phpstan-ignore-next-line */
            if (empty($context['trace']) && $level->value >= Level::Warning) {
                $context['trace'] = self::traceToString($trace, 10);
            }
        }

        return mb_substr($message, 0, $this->messageLimit);
    }

    /**
     * @param array<string, mixed> $context
     * @return void
     */
    public static function contextTypeCorrector(array &$context): void
    {
        foreach ($context as $k => &$r) {
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
                str_contains($k, '_id') || str_contains($k, 'Id') || str_contains($k, '_cnt')
                || str_contains($k, '_count') || str_contains($k, 'Size')
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
