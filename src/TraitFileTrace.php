<?php

namespace Xakki\LaraLog;

trait TraitFileTrace
{
    /** @var string[] */
    private static array $excludedPartials = ['Monolog', 'Logger', 'Illuminate/Log/', 'vendor/laravel'];

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
        return $str == __FILE__ || count(array_filter(self::$excludedPartials, function ($v) use ($str) {
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
    public static function traceToString(array $trace, int $limit = 50): string
    {
        $i = 0;
        $newTrace = [];
        foreach ($trace as &$item) {
            if (! empty($item['file']) && self::checkExcludePart($item['file'])) {
                continue;
            }
            $str = '#' . $i++ . ' ';
            if (! empty($item['file'])) {
                $str .= self::getRelativeFilePath($item['file']) . ':' . ($item['line'] ?? '');
            }
            if (! empty($item['class']) || ! empty($item['function'])) {
                $str .= self::renderTraceWithClass($item);
            }
            $newTrace[] = $str;
            if ($i >= $limit) {
                break;
            }
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
            foreach ($item['args'] as &$arg) {
                if (is_object($arg)) {
                    $arg = get_class($arg);
                } elseif (is_array($arg)) {
                    $arg = '[...' . count($arg) . ']';
                } elseif (is_bool($arg)) {
                    $arg = $arg ? 'T' : 'F';
                } elseif (is_string($arg)) {
                    $arg = '"' . mb_substr($arg, 0, 128) . '"';
                } else {
                    $arg = mb_substr(var_export($arg, true), 0, 128);
                }
                $args .= ($arg ? ', ' : '') . $arg;
            }
        }
        return ' | ' . (! empty($item['class']) ? $item['class'] . '::' : '')
            . (! empty($item['function']) ? $item['function'] . '(' . $args . ')' : '');
    }
}
