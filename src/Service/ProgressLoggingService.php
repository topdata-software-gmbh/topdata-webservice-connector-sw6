<?php

namespace Topdata\TopdataConnectorSW6\Service;


/**
 * 10/2024 created (extracted from MappingHelperService)
 * TODO: merge this into CliStyle
 */
class ProgressLoggingService
{

    private float $microtime;

    public function __construct()
    {
        $this->microtime = microtime(true);


    }

    private static function isCLi(): bool
    {
        return php_sapi_name() == 'cli';
    }

    private static function getNewline(): string
    {
        if (self::isCli()) {
            return "\n";
        } else {
            return '<br>';
        }
    }

    private static function _getCaller()
    {
        $ddSource = debug_backtrace()[1];

        return basename($ddSource['file']) . ':' . $ddSource['line'] . self::getNewline();
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
        \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->write($str);
        \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->write(str_repeat(' ', $padding));
        \Topdata\TopdataFoundationSW6\Util\CliLogger::getCliStyle()->write($caller, $newLine);
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
