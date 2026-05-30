<?php

namespace Xakki\LaraLog;

trait TraitFileTrace
{
    /** @var string[] */
    private const DEFAULT_EXCLUDED_PARTIALS = ['Monolog', 'Illuminate/Log/', 'vendor/'];

    /** @var string[]|null */
    private static ?array $excludedPartials = null;

    private static ?int $traceArgLimit = null;

    /** @return string[] */
    private static function excludedPartials(): array
    {
        if (self::$excludedPartials === null) {
            self::$excludedPartials = (array) config('logger.trace.excluded_partials', self::DEFAULT_EXCLUDED_PARTIALS);
        }
        return self::$excludedPartials;
    }

    private static function traceArgLimit(): int
    {
        if (self::$traceArgLimit === null) {
            self::$traceArgLimit = (int) config('logger.trace.arg_limit', 128);
        }
        return self::$traceArgLimit;
    }

    /** Test seam: drop cached trace config so a changed config() is re-read. */
    public static function flushTraceConfig(): void
    {
        self::$excludedPartials = null;
        self::$traceArgLimit = null;
    }

    /**
     * @param array<int,array{function: string, line?: int, file?:string, class?: class-string,
     *     type?: string, args?: mixed[], object?: object}> $trace
     * @return string
     */
    public static function getFileLine(array $trace): string
    {
        foreach ($trace as $item) {
            if (! empty($item['file'])) {
                if (self::checkExcludePart($item['file'])) {
                    continue;
                }
                return self::getRelativeFilePath($item['file']) . ':' . ($item['line'] ?? '' );
            }
        }
        return '';
    }

    public static function checkExcludePart(string $str): bool
    {
        return strpos($str, __DIR__) !== false
            || count(array_filter(self::excludedPartials(), function ($v) use ($str) {
                return strpos($str, $v) !== false;
            }));
    }

    public static function getRelativeFilePath(string $file): string
    {
        //@phpstan-ignore-next-line
        return str_replace(app()->basePath(), '', $file);
    }

    /**
     * @param array<int,array{function: string, line?: int, file?:string, class?: class-string,
     *     type?: string, args?: mixed[], object?: object}> $trace
     * @param int $limit
     * @return string
     */
    public static function traceToString(array $trace, int $limit = 50, bool $checkExcludePart = true): string
    {
        $i = 0;
        $newTrace = [];
        $skippedLine = 0;
        foreach ($trace as &$item) {

            $f = false;
            if (!empty($item['file']) && self::checkExcludePart($item['file'])) {
                $f = true;
                if ($checkExcludePart) {
                    $skippedLine++;
                    continue;
                }
            }

            if ($skippedLine > 0) {
                $newTrace[] = str_repeat('.', $skippedLine);
                $skippedLine = 0;
            }

            $str = '#' . $i++ . ' ';
            if (! empty($item['file'])) {
                $str .= self::getRelativeFilePath($item['file']) . ':' . ($item['line'] ?? '');
            }

            if (!$f) {
                if (! empty($item['class']) || ! empty($item['function'])) {
                    $str .= self::renderTraceWithClass($item);
                }
            }
            $newTrace[] = $str;
            if ($i >= $limit) {
                $newTrace[] = '***';
                break;
            }
        }

        if ($skippedLine > 0) {
            $newTrace[] = str_repeat('.', $skippedLine);
        }
        return implode(PHP_EOL, $newTrace);
    }

    /**
     * @param array{function: string, line?: int, file?:string, class?: class-string,
     *      type?: string, args?: mixed[], object?: object} $item
     * @return string
     */
    protected static function renderTraceWithClass(array $item): string
    {
        $args = '';
        if (! empty($item['args'])) {
            $limit = self::traceArgLimit();
            foreach ($item['args'] as &$arg) {
                if (is_object($arg)) {
                    $arg = get_class($arg);
                } elseif (is_array($arg)) {
                    $arg = '[...' . count($arg) . ']';
                } elseif (is_bool($arg)) {
                    $arg = $arg ? 'T' : 'F';
                } elseif (is_string($arg)) {
                    // Args are positional (no name) -> value-pattern redaction (§2.1).
                    $arg = '"' . mb_substr(Redactor::redactValue($arg), 0, $limit) . '"';
                } else {
                    $arg = mb_substr(Redactor::redactValue(var_export($arg, true)), 0, $limit);
                }
                $args .= ($arg ? ', ' : '') . $arg;
            }
        }
        return ' | ' . (! empty($item['class']) ? $item['class'] . '::' : '')
            . (! empty($item['function']) ? $item['function'] . '(' . $args . ')' : '');
    }
}
