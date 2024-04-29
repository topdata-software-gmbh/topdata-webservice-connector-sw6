<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ConnectorImportTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata.connector_import_task';
    }

    public static function getDefaultInterval(): int
    {
        return 3600*24; // once a day
    }
}