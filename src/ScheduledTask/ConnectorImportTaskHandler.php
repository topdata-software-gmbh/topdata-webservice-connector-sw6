<?php

declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\ScheduledTask;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Topdata\TopdataConnectorSW6\Service\ScheduledImportRunnerService;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

class ConnectorImportTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ScheduledImportRunnerService $scheduledImportRunnerService,
        private readonly SystemConfigService $systemConfigService,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public static function getHandledMessages(): iterable
    {
        return [ConnectorImportTask::class];
    }

    public function run(): void
    {
        $isEnabled = $this->systemConfigService->getBool('TopdataConnectorSW6.config.enableScheduledImport');
        if (!$isEnabled) {
            CliLogger::info('Scheduled Topdata import is disabled in plugin configuration. Skipping execution.');

            return;
        }

        $this->scheduledImportRunnerService->runFullImportForScheduledTask();
    }
}
