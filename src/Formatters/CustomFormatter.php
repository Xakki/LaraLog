<?php

namespace Xakki\LaraLog\Formatters;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

class CustomFormatter extends NormalizerFormatter
{
    public function format(LogRecord $record): string
    {
        $recordData = $this->normalizeRecord($record);
        unset($recordData['channel']);
        if (isset($recordData['level'])) {
            $recordData['monolog_level'] = $recordData['level'];
            unset($recordData['level']);
        }
        return $this->toJson($recordData) . "\n";
    }
}
