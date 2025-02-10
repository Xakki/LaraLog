<?php

namespace Xakki\LaraLog\Formatters;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

class ConsoleFormatter extends LineFormatter
{
    public const COLOR_GREEN = '0;32',
        COLOR_GRAY = '0;37',
        COLOR_GRAY_DARK = '1;30',
        COLOR_YELLOW = '1;33',
        COLOR_BROWN = '0;33',
        COLOR_RED = '0;31',
        COLOR_RED_LIGHT = '1;31',
        COLOR_PURPLE = '0;35',
        COLOR_WHITE = '1;37',
        COLOR_BLUE_LIGHT = '1;34',
        COLOR_BLUE = '0;34',
        COLOR_CYAN_LIGHT = '1;36';
    public const CLI_LEVEL_COLOR = [
        LOG_EMERG => self::COLOR_PURPLE,
        LOG_ALERT => self::COLOR_PURPLE,
        LOG_CRIT => self::COLOR_RED,
        LOG_ERR => self::COLOR_RED,
        LOG_WARNING => self::COLOR_YELLOW,
        LOG_NOTICE => self::COLOR_BLUE,
        LOG_INFO => self::COLOR_GREEN,
        LOG_DEBUG => self::COLOR_CYAN_LIGHT,
    ];

    public function format(LogRecord $record): string
    {
        $this->format = $this->getCliFormat($record);
        return parent::format($record);
    }

    /**
     * Для отображения логов при локальной разработке.
     * Форматирует в читаемы вид
     */
    public function getCliFormat(LogRecord $record): string
    {
        $format = '[' . $record->datetime->format('H:i:s') . '] '
            . self::cliColor('%channel%', static::COLOR_GRAY_DARK) . '.'
            . self::cliColor('%level_name%', static::CLI_LEVEL_COLOR[$record->level->toRFC5424Level()]);

        $format .= "\n" . self::cliColor('%message%', static::COLOR_CYAN_LIGHT);

        if (!empty($record->context)) {
            $format .= "\n  " . self::cliColor('%context%', static::COLOR_BLUE_LIGHT);
        }

        if (!empty($record->extra)) {
            $format .= "\n  " . self::cliColor('%extra%', static::COLOR_BLUE_LIGHT);
        }

//        if (!empty($log['trace'])) {
//            $output .= "\n    " . self::cliColor(self::textCliPrepare($log['trace']), static::COLOR_GRAY);
//        } elseif (!empty($log['fileLine'])) {
//            $output .= "\n  " . self::cliColor($log['fileLine'], static::COLOR_GRAY_DARK);
//        }
//
//        if (!empty($log['exception'])) {
//            $output .= "\n  Exception: " . self::cliColor($log['exception'] . ' [' . $log['exceptionCode'] . ']',
// static::COLOR_RED_LIGHT);
//            $output .= "\n    " . self::cliColor($log['exceptionFile_line'], static::COLOR_GRAY);
//            if (!empty($log['previousMessage'])) {
//                $output .= "\n\tPrevious: " . self::cliColor($log['previousMessage'], static::COLOR_GRAY_DARK);
//            }
//        }

        return $format . PHP_EOL . PHP_EOL;
    }

    public static function cliColor(string $text, string $colorId): string
    {
        $text = explode(PHP_EOL, $text);
        $text = implode("\033[0m" . PHP_EOL . "\033[" . $colorId . "m", $text);
        return "\033[" . $colorId . "m" . $text . "\033[0m";
    }
}
