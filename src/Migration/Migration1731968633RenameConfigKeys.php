<?php declare(strict_types=1);

namespace Topdata\TopdataConnectorSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1731968633RenameConfigKeys extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1731968633;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            UPDATE system_config 
            SET configuration_key = "TopdataConnectorSW6.config.apiUid" 
            WHERE configuration_key = "TopdataConnectorSW6.config.apiUsername"
        ');

        $connection->executeStatement('
            UPDATE system_config 
            SET configuration_key = "TopdataConnectorSW6.config.apiPassword" 
            WHERE configuration_key = "TopdataConnectorSW6.config.apiKey"
        ');

        $connection->executeStatement('
            UPDATE system_config 
            SET configuration_key = "TopdataConnectorSW6.config.apiSecurityKey" 
            WHERE configuration_key = "TopdataConnectorSW6.config.apiSalt"
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}