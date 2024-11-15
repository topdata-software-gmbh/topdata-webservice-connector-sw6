<?php

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataFoundationSW6\Trait\CliStyleTrait;
use TopdataSoftwareGmbH\Util\UtilTextOutput;

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


    private static function _getCaller()
    {
        $ddSource = debug_backtrace()[1];
        return basename($ddSource['file']) . ':' . $ddSource['line'] . UtilTextOutput::getNewline();
    }

    /**
     * Helper method for logging stuff to stdout with right-aligned caller information.
     */
    public function activity(string $str = '.', bool $newLine = false): void
    {
        // Get terminal width, default to 80 if can't determine
        $terminalWidth = (int) (`tput cols` ?? 80);
        // Get caller information
        $caller = self::_getCaller();
        $callerLength = strlen($caller);

        // Calculate padding needed
        $messageLength = strlen($str);
        $padding = max(0, $terminalWidth - $messageLength - $callerLength);

        // Write the message, padding, and caller
        $this->cliStyle->write($str);
        $this->cliStyle->write(str_repeat(' ', $padding));
        $this->cliStyle->write($caller, $newLine);
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

}
