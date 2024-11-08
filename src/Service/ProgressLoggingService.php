<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;

/**
 * 10/2024 created (extracted from MappingHelperService)
 * TODO: merge this into CliStyle.
 */
class ProgressLoggingService
{
    use CliStyleTrait;

    private float $microtime;

    public function __construct()
    {
        $this->beVerboseOnCli();
        $this->microtime = microtime(true);
    }

    /**
     * helper method for logging stuff to stdout.
     */
    public function activity(string $str = '.', bool $newLine = false): void
    {
        $this->cliStyle->write($str);
        if ($newLine) {
            $this->cliStyle->write("\n");
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
        $lapTime = microtime(true) - $this->microtime;
        $this->microtime = microtime(true);

        return (string)round($lapTime, 3);
    }

    /**
     * 10/2024 created.
     */
    public function writeln(string $string): void
    {
        $this->cliStyle->writeln($string);
    }
}
