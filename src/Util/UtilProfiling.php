<?php

namespace Topdata\TopdataConnectorSW6\Util;

use Exception;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

/**
 * 04/2025 created.
 */
class UtilProfiling
{
    private static array $startTimes = [];
    private static array $profiling = [];

    /**
     * TODO: move to TopdataFoundationSW6
     *
     * 04/2025 created
     */
    public static function startTimer(): void
    {
        $key = self::_getCallerKey(1);
        self::$startTimes[$key] = microtime(true);
    }

    /**
     * TODO: move to TopdataFoundationSW6
     * TODO: use some DTO
     *
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
     * TODO: move to TopdataFoundationSW6
     *
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
     * TODO: move to TopdataFoundationSW6
     *
     * 04/2025 created
     */
    public static function getProfiling(): array
    {
        return self::$profiling;
    }

    /**
     * TODO: move to Foundation plugin
     * TODO: use some DTO defined in the foundation plugin
     * TODO: move the Repo
     *
     * It prints the profiling data in a table
     * 04/2025 created
     *
     * @param array $profiling , format: [
     *      'Class::method' => [
     *          'time' => 8123.123, // in seconds
     *          'count' => 22,
     *      ]
     * ]
     */
    public static function dumpProfiling(): void
    {
        $rows = [];
        foreach (self::getProfiling() as $key => $val) {
            $rows[] = [
                $key,
                number_format($val['time'], 2),
                $val['count'],
            ];
        }

        CliLogger::getCliStyle()->table(['Method', 'Total Time', 'Call Count'], $rows, 'Profiling');
    }
}
