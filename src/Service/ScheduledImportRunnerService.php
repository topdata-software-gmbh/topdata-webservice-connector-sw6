<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Service;

use Topdata\TopdataConnectorSW6\DTO\ImportConfig;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

class ScheduledImportRunnerService
{
    public function __construct(
        private readonly ImportService $importService,
    ) {
    }

    public function runFullImportForScheduledTask(): void
    {
        try {
            CliLogger::info('Starting automatic scheduled Topdata import (--all).');
            $config = ImportConfig::createForScheduledTaskAll();
            $this->importService->execute($config);
            CliLogger::info('Automatic scheduled Topdata import finished successfully.');
        } catch (\Throwable $exception) {
            CliLogger::error('Automatic scheduled Topdata import failed: ' . $exception->getMessage());

            throw $exception;
        }
    }
}
