<?php

namespace Xakki\LaraLog\Formatters;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class CustomFormatter extends NormalizerFormatter
{
    protected int $logSize = 1400;

    public function __construct(?string $dateFormat = null, ?int $logSize = null)
    {
        if ($logSize) {
            $this->logSize = $logSize;
        }
        parent::__construct($dateFormat);
    }

    public function format(LogRecord $record): string
    {
        $recordData = $this->normalizeRecord($record);
        $recordData['level'] = $record->level->toRFC5424Level();

        if (isset($recordData['context']) && is_array($recordData['context'])) {
            foreach ($recordData['context'] as $k => &$v) {
                if (is_null($v)) {
                    unset($recordData['context'][$k]);
                }
            }
        }

        if (isset($recordData['extra']) && is_array($recordData['extra'])) {
            foreach ($recordData['extra'] as $k2 => &$v2) {
                if (is_null($v2)) {
                    unset($recordData['extra'][$k2]);
                }
            }
        }

        return $this->trimLog($recordData) . "\n";
    }

    /**
     * @param array<string, mixed> $recordData
     * @return string
     */
    protected function trimLog(array $recordData): string
    {
        $log = (string) json_encode($recordData, JSON_UNESCAPED_UNICODE);

        if (json_last_error()) {
            fwrite(\STDERR, '[' . date('Y-m-d h:i:s') . '] CustomFormatter json error [' . json_last_error() . '] '
                . json_last_error_msg() . PHP_EOL);
            return '';
        }
        $len = strlen($log);
        if (!$this->logSize || $len < $this->logSize) {
            return $log;
        }
        $limit = $this->logSize * 0.4;
        while ($len > $this->logSize) {
            self::trimData($recordData['message'], $limit);
            foreach ($recordData['context'] as &$v) {
                if (is_string($v)) {
                    self::trimData($v, $limit * 0.6);
                }
            }
            foreach ($recordData['extra'] as &$v) {
                if (is_string($v)) {
                    self::trimData($v, $limit * 0.6);
                }
            }
            $len = self::getLen($recordData);
            $limit = $limit * 0.7;
        }

        return (string) json_encode($recordData, JSON_UNESCAPED_UNICODE);
    }



    protected function trimData(string &$str, int|float $limit): void
    {
        if (strlen($str) > $limit) {
            $str = mb_substr($str, 0, (int) ($limit / 2)) . '…';// Multibyte
        }
    }

    protected function getLen(mixed $v): int
    {
        return strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE));
    }
}
