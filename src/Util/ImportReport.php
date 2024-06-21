<?php

namespace Topdata\TopdataConnectorSW6\Util;

/**
 * 06/2024 created
 */
class ImportReport
{
    /**
     * this is always a dict (never a list).
     */
    protected static array $counters = [];


    public static function incCounter(string $key, int $inc = 1): void
    {
        self::$counters[$key] = (self::$counters[$key] ?? 0) + $inc;
    }

    public static function setCounter(string $key, int $count): void
    {
        self::$counters[$key] = $count;
    }

    public static function getCounters(): array
    {
        return self::$counters;
    }

    public static function getCountersSorted(): array
    {
        // sort by key
        ksort(self::$counters);

        return self::$counters;
    }

    public static function getCounter(string $key): ?int
    {
        return self::$counters[$key] ?? null; // GlobalConfigConstants::NUM_ROWS__FAILED; // -2 is a magic number
    }


}