<?php

namespace Topdata\TopdataConnectorSW6\Util;

use Exception;
use Topdata\TopdataFoundationSW6\Util\CliLogger;
use Topdata\TopdataFoundationSW6\Util\UtilFormatter;

/**
 * TODO: move to TopdataFoundationSW6
 *
 * 04/2025 created.
 */
class UtilProfiling
{
    private static array $startTimes = [];
    private static array $profiling = [];

    /**
     * 04/2025 created
     */
    public static function startTimer(): void
    {
        $key = self::_getCallerKey(1);
        self::$startTimes[$key] = microtime(true);
    }

    /**
     * 04/2025 created
     */
    public static function stopTimer(): void
    {
        $key = self::_getCallerKey(1);
        if(!isset(self::$startTimes[$key])) {
            throw new Exception("Timer for $key not started");
        }
        if(!isset(self::$profiling[$key])) {
            self::$profiling[$key] = [
                'time' => 0,
                'count' => 0,
            ];
        }
        self::$profiling[$key]['time'] += microtime(true) - self::$startTimes[$key];
        self::$profiling[$key]['count']++;
        self::$startTimes[$key] = null;
    }

    /**
     * 04/2025 created
     */
    private static function _getCallerKey(int $skip = 0): string
    {
        // get caller key
        $trace = debug_backtrace();
        $caller = $trace[$skip + 1];

        return $caller['class'] . '::' . $caller['function'];
    }

    /**
     * TODO: use some DTO defined in the foundation plugin
     * *
     * @return array, format: [
     *       [
     *           'method'    => 'Class::method',
     *           'time' => 8123.123, // in seconds
     *           'count' => 22,
     *       ], ...
     *  ]
     * 04/2025 created
     * 05/2025 added sorting
     */
    public static function getProfiling(string|null $sortBy = 'time'): array
    {
        $ret = [];
        foreach (self::$profiling as $key => $val) {
            $ret[] = [
                'method'    => $key,
                'time'      => $val['time'],
                'count'     => $val['count'],
            ];
        }

        // ---- sorting
        if ($sortBy) {
            usort($ret, fn($a, $b) => $a[$sortBy] <=> $b[$sortBy]);
        }

        return $ret;
    }

    /**
     * It prints the profiling data in a table
     *
     * 04/2025 created
     *
     */
    public static function dumpProfilingToCli(): void
    {
        $rows = [];
        foreach (self::getProfiling() as  $row) {
            $rows[] = [
                $row['method'],
                UtilFormatter::formatDuration($row['time']),
                number_format($row['count'], 0, ',', '.'),
            ];
        }

        CliLogger::getCliStyle()->table(['Method', 'Total Time', 'Call Count'], $rows, 'Profiling');
    }
}
