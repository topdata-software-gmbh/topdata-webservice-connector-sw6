<?php

namespace Topdata\TopdataConnectorSW6\Service;

/**
 * 10/2024 created (extracted from MappingHelperService)
 * TODO: merge this into CliStyle.
 */
class ProgressLoggingService
{
    private bool $verbose;
    private float $microtime;

    public function __construct()
    {
        $this->microtime = microtime(true);
    }

    /**
     * helper method for logging stuff to stdout.
     */
    public function activity(string $str = '.', bool $newLine = false): void
    {
        if ($this->verbose) {
            echo $str;
            if ($newLine) {
                echo "\n";
            }
        }
    }

    /**
     * logging helper.
     */
    public function mem(): void
    {
        $this->activity('[' . round(memory_get_usage(true) / 1024 / 1024) . 'Mb]');
    }

    /**
     * logging helper.
     */
    public function lap($start = false): string
    {
        if ($start) {
            $this->microtime = microtime(true);

            return '';
        }
        $lapTime         = microtime(true) - $this->microtime;
        $this->microtime = microtime(true);

        return (string) round($lapTime, 3);
    }

    public function setVerbose(bool $verbose): void
    {
        $this->verbose = $verbose;
    }

    /**
     * 10/2024 created.
     */
    public function writeln(string $string): void
    {
        if (!$this->verbose) {
            return;
        }
        echo $string . "\n";
    }
}
