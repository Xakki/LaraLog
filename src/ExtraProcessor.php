<?php

declare(strict_types=1);

namespace Xakki\LaraLog;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class ExtraProcessor implements ProcessorInterface
{
    use TraitFileTrace;

    private Level $levelTrace;

    /**
     * @param int|string|Level $levelTrace
     * @param string[] $skipClassesOrFilesPartials
     */
    public function __construct(
        int|string|Level $levelTrace = Level::Warning,
        array $skipClassesOrFilesPartials = [],
    ) {
        /** @phpstan-ignore-next-line */
        $this->levelTrace = \Monolog\Logger::toMonologLevel($levelTrace);
        self::$excludedPartials = array_merge(self::$excludedPartials, $skipClassesOrFilesPartials);
    }

    /**
     * @inheritDoc
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $record->extra['duration'] = time() - (int) $_SERVER['REQUEST_TIME'];
        $record->extra['memory_peak'] = memory_get_peak_usage();
        $record->extra['memory_usage'] = memory_get_usage();
        $record->extra['request_id'] = CommonLogger::getOrCreateRequestId();

        $trace = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60), 5);

        if (empty($record->context['exception_file'])) {
            $record->extra['file'] = $this->getFileLine($trace);
        } else {
            $record->extra['file'] = $record->context['exception_file'];
        }

        if (empty($record->context['exception_class']) && ! $record->level->isLowerThan($this->levelTrace)) {
            $record->extra['trace'] = $this->traceToString($trace);
        }

        return $record;
    }
}
